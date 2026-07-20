<?php
require_once '../config/config.php';
require_once '../auth/auth_middleware.php';

require_trader();
require_mandate();

$query = $_GET;
$query['view'] = 'orders';

header('Location: dealing_sheet.php?' . http_build_query($query));
exit;
