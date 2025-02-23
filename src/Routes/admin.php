<?php
use Illuminate\Support\Facades\Route;

foreach (glob(__DIR__ . '/Admin/*.php') as $filename) {
    $this->loadRoutesFrom($filename);
}
