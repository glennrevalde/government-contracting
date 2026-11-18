<?php
declare(strict_types=1);

// index.php?p=file.lhpic&f=lhpic_....png : a picture of a letterhead (Proforma & Form Templates > Format), for the forms
// printed with it (logged-in users only, like the company logo)
$f = (string)g('f');
$path = BrgyDocs::dir() . '/' . $f;
if (!preg_match('/^lhpic_[a-z0-9_]+\.(png|jpg)$/', $f) || !is_file($path)) {
    http_response_code(404);
    exit;
}
header('Content-Type: ' . (str_ends_with($f, '.png') ? 'image/png' : 'image/jpeg'));
header('Cache-Control: private, max-age=86400');
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;
