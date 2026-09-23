<?php

declare(strict_types=1);

require __DIR__ . '/../../private_html/bootstrap.php';
require __DIR__ . '/../partials/layout.php';

$currentUser = Auth::requireAdmin();
$pdo = Database::pdo();

function random_temp_password(): string
{
    return substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(9))), 0, 12) . 'Aa1';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
        Flash::error('Your session expired. Please try again.');
        header('Location: /admin/users.php');
        exit;
    }

    $action = $_POST['form_action'] ?? '';

    if ($action === 'create_user') {
        $displayName = trim((string) ($_POST['display_name'] ?? ''));
        $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
        $role = ($_POST['role'] ?? 'user') === 'admin' ? 'admin' : 'user';

        if ($displayName === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Flash::error('Please provide a valid name and email address.');
        } else {
            $exists = $pdo->prepare('SELECT id FROM users WHERE email = :email');
            $exists->execute(['email' => $email]);
            if ($exists->fetchColumn() !== false) {
                Flash::error('An account with that email already exists.');
            } else {
                $tempPassword = random_temp_password();
                $stmt = $pdo->prepare(
                    'INSERT INTO users (email, password_hash, display_name, role, must_change_password) VALUES (:email, :hash, :name, :role, 1)'
                );
                $stmt->execute([
                    'email' => $email, 'hash' => Auth::hashPassword($tempPassword),
                    'name' => $displayName, 'role' => $role,
                ]);
                $newUserId = (int) $pdo->lastInsertId();
                Seed::forNewUser($newUserId);
                Audit::log((int) $currentUser['id'], 'user_created', 'users', $newUserId);

                $body = '<p>Hi ' . Format::e($displayName) . ',</p>'
                    . '<p>An account has been created for you on ' . Format::e((string) Config::get('site_name', 'Pennywise')) . '.</p>'
                    . '<p>Email: ' . Format::e($email) . '<br>Temporary password: <strong>' . Format::e($tempPassword) . '</strong></p>'
                    . '<p>You will be asked to set a new password when you first log in: <a href="' . Format::e(Config::siteUrl() . '/login.php') . '">' . Format::e(Config::siteUrl() . '/login.php') . '</a></p>';
                $mailed = Mailer::send($email, $displayName, 'Your account is ready', $body);

                Flash::success('User created. Temporary password: ' . $tempPassword . ($mailed ? ' (also emailed to them).' : ' (email could not be sent - share this password securely yourself).'));
            }
        }
    } elseif ($action === 'update_user') {
        $userId = (int) ($_POST['user_id'] ?? 0);
        if ($userId === (int) $currentUser['id'] && ($_POST['role'] ?? 'admin') !== 'admin') {
            Flash::error('You cannot remove your own admin role.');
        } else {
            $displayName = trim((string) ($_POST['display_name'] ?? ''));
            $role = ($_POST['role'] ?? 'user') === 'admin' ? 'admin' : 'user';
            $isActive = !empty($_POST['is_active']) ? 1 : 0;

            if ($userId === (int) $currentUser['id']) {
                $isActive = 1;
            }

            $stmt = $pdo->prepare('UPDATE users SET display_name = :name, role = :role, is_active = :active WHERE id = :id');
            $stmt->execute(['name' => $displayName, 'role' => $role, 'active' => $isActive, 'id' => $userId]);
            Audit::log((int) $currentUser['id'], 'user_updated', 'users', $userId);
            Flash::success('User updated.');
        }
    } elseif ($action === 'reset_password') {
        $userId = (int) ($_POST['user_id'] ?? 0);
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = :id');
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch();

        if ($user !== false) {
            $tempPassword = random_temp_password();
            Auth::updatePassword($userId, $tempPassword, false);
            Audit::log((int) $currentUser['id'], 'user_password_reset', 'users', $userId);

            $body = '<p>Hi ' . Format::e($user['display_name']) . ',</p>'
                . '<p>Your password has been reset by an administrator.</p>'
                . '<p>Temporary password: <strong>' . Format::e($tempPassword) . '</strong></p>'
                . '<p>You will be asked to set a new password when you next log in.</p>';
            $mailed = Mailer::send($user['email'], $user['display_name'], 'Your password has been reset', $body);

            Flash::success('Password reset. Temporary password: ' . $tempPassword . ($mailed ? ' (also emailed to them).' : ' (email could not be sent - share this securely yourself).'));
        }
    }

    header('Location: /admin/users.php');
    exit;
}

$users = $pdo->query('SELECT * FROM users ORDER BY display_name')->fetchAll();

render_header('Manage users', 'admin', $currentUser);
?>
<div class="topbar"><h1>Manage users</h1></div>

<div class="card">
  <h2>Add a user</h2>
  <p class="hint">Each account gets its own fully private budget with a starter set of categories. There is no public sign-up - only admins can create accounts.</p>
  <form method="post" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
    <?= Csrf::field() ?>
    <input type="hidden" name="form_action" value="create_user">
    <label style="margin:0;">Name<input type="text" name="display_name" required></label>
    <label style="margin:0;">Email<input type="email" name="email" required></label>
    <label style="margin:0;">Role
      <select name="role"><option value="user">User</option><option value="admin">Admin</option></select>
    </label>
    <button class="btn" type="submit">Create account</button>
  </form>
</div>

<div class="card">
  <h2>All users</h2>
  <table>
    <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Active</th><th>Last login</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($users as $u): $fid = 'user-form-' . (int) $u['id']; ?>
        <tr>
          <td><input form="<?= $fid ?>" type="text" name="display_name" value="<?= Format::e($u['display_name']) ?>" style="min-width:150px;"></td>
          <td><?= Format::e($u['email']) ?></td>
          <td>
            <select form="<?= $fid ?>" name="role" <?= (int) $u['id'] === (int) $currentUser['id'] ? 'disabled' : '' ?>>
              <option value="user" <?= $u['role'] === 'user' ? 'selected' : '' ?>>User</option>
              <option value="admin" <?= $u['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
            </select>
          </td>
          <td><input form="<?= $fid ?>" type="checkbox" name="is_active" <?= $u['is_active'] ? 'checked' : '' ?> <?= (int) $u['id'] === (int) $currentUser['id'] ? 'disabled checked' : '' ?>></td>
          <td><?= $u['last_login_at'] ? Format::date($u['last_login_at']) : 'Never' ?></td>
          <td style="white-space:nowrap;">
            <button form="<?= $fid ?>" type="submit" name="form_action" value="update_user" class="btn small secondary">Save</button>
            <button form="<?= $fid ?>" type="submit" name="form_action" value="reset_password" data-confirm="Reset this user's password to a new temporary one?" class="btn small danger">Reset password</button>
          </td>
        </tr>
        <tr style="display:none;"><td colspan="6">
          <form id="<?= $fid ?>" method="post">
            <?= Csrf::field() ?>
            <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
          </form>
        </td></tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php render_footer(['/assets/js/app.js']); ?>
