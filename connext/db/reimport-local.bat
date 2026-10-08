@echo off
cd /d C:
mpp\htdocs\connext
C:
mpp\php\php.exe db\import_from_sheet.php --fresh
C:
mpp\php\php.exe dbdd_local_admin.php
pause
