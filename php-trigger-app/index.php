<?php
declare(strict_types=1);

// BuildConfig trigger demo: change the two constants below, commit and push.
// The webhook starts a new build and the page shows the new text.

const MESSAGE = 'Hello from build trigger demo - version 1';
const COLOR   = '#777bb3';

function env(string $name): string
{
    $v = getenv($name);
    return $v === false || $v === '' ? '-' : $v;
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

// The S2I builder sets the OPENSHIFT_BUILD_* variables in the image, so they
// change on every build even when the text stays the same.
$rows = [
    'Build'     => env('OPENSHIFT_BUILD_NAME'),
    'Commit'    => substr(env('OPENSHIFT_BUILD_COMMIT'), 0, 12),
    'Branch'    => env('OPENSHIFT_BUILD_REFERENCE'),
    'Source'    => env('OPENSHIFT_BUILD_SOURCE'),
    'Namespace' => env('OPENSHIFT_BUILD_NAMESPACE'),
    'Pod'       => gethostname() ?: '-',
    'PHP'       => PHP_VERSION,
    'Time'      => gmdate('Y-m-d H:i:s') . ' UTC',
];

header('Cache-Control: no-store');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>PHP build trigger demo</title>
<style>
  body { margin: 0; font-family: system-ui, sans-serif; background: #f4f4f7; color: #222; }
  header { background: <?= e(COLOR) ?>; color: #fff; padding: 48px 16px; text-align: center; }
  h1 { margin: 0; font-size: 2rem; }
  main { max-width: 640px; margin: 24px auto; padding: 0 16px; }
  table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 8px; overflow: hidden; }
  th, td { text-align: left; padding: 10px 14px; border-bottom: 1px solid #eee; }
  th { width: 30%; color: #666; font-weight: 600; }
  td { font-family: ui-monospace, monospace; word-break: break-all; }
  p { color: #666; font-size: .9rem; }
</style>
</head>
<body>
<header>
  <h1><?= e(MESSAGE) ?></h1>
</header>
<main>
  <table>
<?php foreach ($rows as $label => $value): ?>
    <tr><th><?= e($label) ?></th><td><?= e($value) ?></td></tr>
<?php endforeach; ?>
  </table>
  <p>Edit <code>MESSAGE</code> in <code>php-trigger-app/index.php</code>, commit and push to start a new build.</p>
</main>
</body>
</html>
