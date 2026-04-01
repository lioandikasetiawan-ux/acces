<?php
http_response_code(403);
echo json_encode([
    "status" => false,
    "message" => "Forbidden"
]);
exit;
