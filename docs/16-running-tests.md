# 16 - Running tests locally

## Install dependencies

```bash
composer install
```

## Linux, macOS or Git Bash

```bash
./vendor/bin/phpunit
./vendor/bin/pint --test
```

## Windows PowerShell

```powershell
.\vendor\bin\phpunit.bat
.\vendor\bin\pint.bat --test
```

## Windows CMD

```bat
vendor\bin\phpunit.bat
vendor\bin\pint.bat --test
```

## When Pint fails

`pint --test` only checks formatting. It does not modify files.

To automatically format the project, run:

```bash
./vendor/bin/pint
```

On Windows PowerShell:

```powershell
.\vendor\bin\pint.bat
```

Then rerun:

```bash
./vendor/bin/pint --test
```

## When PHPUnit cannot find tests

The package includes `phpunit.xml.dist`. Run PHPUnit from the repository root:

```bash
./vendor/bin/phpunit
```

If you are using Windows PowerShell, prefer:

```powershell
.\vendor\bin\phpunit.bat
```
