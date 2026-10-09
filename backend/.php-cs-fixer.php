<?php
declare(strict_types=1);
return (new PhpCsFixer\Config())->setRiskyAllowed(true)->setRules(['@PSR12' => true, 'declare_strict_types' => true])->setFinder(PhpCsFixer\Finder::create()->in([__DIR__ . '/src', __DIR__ . '/bin', __DIR__ . '/public', __DIR__ . '/tests', __DIR__ . '/migrations']))->setCacheFile(__DIR__ . '/var/.php-cs-fixer.cache');
