@echo off
setlocal EnableExtensions EnableDelayedExpansion

cd /d "%~dp0"

where git >nul 2>nul
if errorlevel 1 (
    echo Git is niet gevonden in PATH.
    pause
    exit /b 1
)

git rev-parse --is-inside-work-tree >nul 2>nul
if errorlevel 1 (
    echo Deze map is geen Git repository: %CD%
    pause
    exit /b 1
)

for /f "usebackq delims=" %%B in (`git branch --show-current`) do set "BRANCH=%%B"

if "%BRANCH%"=="" (
    echo Geen actieve branch gevonden. Mogelijk staat de repository in detached HEAD.
    pause
    exit /b 1
)

echo Repository: %CD%
echo Branch: %BRANCH%
echo.

git status --short
echo.

git add -A
git diff --cached --quiet
if errorlevel 1 (
    git commit -m "Automatische update %DATE% %TIME%"
    if errorlevel 1 (
        echo Commit mislukt.
        pause
        exit /b 1
    )
) else (
    echo Geen lokale wijzigingen om te committen.
)

echo.
echo Ophalen van GitHub met rebase...
git pull --rebase
if errorlevel 1 (
    echo Pull/rebase mislukt. Los eventuele conflicten handmatig op.
    pause
    exit /b 1
)

echo.
echo Pushen naar GitHub...
git push
if errorlevel 1 (
    echo Normale push mislukt. Probeer upstream in te stellen voor %BRANCH%...
    git push -u origin "%BRANCH%"
    if errorlevel 1 (
        echo Push naar GitHub mislukt.
        pause
        exit /b 1
    )
)

echo.
echo GitHub update afgerond.
pause
