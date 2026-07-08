# 16 - Running tests locally

## Recommended commands

Run these commands from the repository root.

```bash
composer install
composer qa
```

`composer qa` runs the full local quality check:

```text
composer pint:test
composer test
```

## Available Composer scripts

| Command | Purpose |
|---|---|
| `composer test` | Run PHPUnit. |
| `composer test:unit` | Run the `Unit` PHPUnit testsuite. |
| `composer pint` | Format the project with Laravel Pint. |
| `composer pint:test` | Check formatting without changing files. |
| `composer format` | Alias for `composer pint`. |
| `composer qa` | Run `composer pint:test` and `composer test`. |

## Windows CMD

If your prompt looks like this:

```bat
D:\Proyectos\Composer Libraries\ronu-laravel-federated-auth>
```

then you are using Windows CMD.

Do not use `./vendor/bin/...` in CMD. Use the Composer scripts instead:

```bat
composer install
composer pint:test
composer test
```

Or run everything together:

```bat
composer qa
```

## Windows PowerShell

Composer scripts work the same way:

```powershell
composer install
composer qa
```

Direct vendor commands also work in PowerShell, but Composer scripts are easier:

```powershell
.\vendor\bin\pint.bat --test
.\vendor\bin\phpunit.bat
```

## Linux, macOS or Git Bash

Composer scripts work everywhere:

```bash
composer install
composer qa
```

Direct vendor commands also work:

```bash
./vendor/bin/pint --test
./vendor/bin/phpunit
```

## When Pint fails

`composer pint:test` only checks formatting. It does not modify files.

To automatically format the project, run:

```bash
composer pint
```

Then rerun:

```bash
composer pint:test
```

## When PHPUnit cannot find tests

The package includes `phpunit.xml.dist`. Run PHPUnit from the repository root:

```bash
composer test
```
