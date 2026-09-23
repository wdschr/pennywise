<?php
// This directory is not meant to be web-accessible. If you can see this,
// your web server's document root is misconfigured - it should point at
// public_html, not the repository root or private_html.
http_response_code(403);
exit('Forbidden');
