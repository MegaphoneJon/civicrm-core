<?php

$extensionRoot = __DIR__ . '/ext';

spl_autoload_register(function (string $className) use ($extensionRoot): void {
  foreach (glob($extensionRoot . '/*/') as $extDir) {
    // CRM directory
    if (str_starts_with($className, 'CRM_')) {
      $file = $extDir . str_replace('_', '/', $className) . '.php';
      if (file_exists($file)) {
        require_once $file;
        return;
      }
    }

    // Civi directory
    if (str_starts_with($className, 'Civi\\')) {
      $file = $extDir . str_replace('\\', '/', $className) . '.php';
      if (file_exists($file)) {
        require_once $file;
        return;
      }
    }
  }
});
