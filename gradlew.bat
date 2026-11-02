@echo off
setlocal
set "GRADLE_VERSION=8.7"
if not defined GRADLE_USER_HOME set "GRADLE_USER_HOME=%USERPROFILE%\.gradle"
set "DIST_DIR=%GRADLE_USER_HOME%\wrapper\dists\gradle-%GRADLE_VERSION%-bin\manual"
set "GRADLE_BIN=%DIST_DIR%\gradle-%GRADLE_VERSION%\bin\gradle.bat"
set "ZIP=%DIST_DIR%\gradle-%GRADLE_VERSION%-bin.zip"

if not exist "%GRADLE_BIN%" (
  if not exist "%DIST_DIR%" mkdir "%DIST_DIR%"
  if not exist "%ZIP%" (
    echo Downloading Gradle %GRADLE_VERSION%...
    powershell -NoProfile -ExecutionPolicy Bypass -Command "Invoke-WebRequest -Uri 'https://services.gradle.org/distributions/gradle-%GRADLE_VERSION%-bin.zip' -OutFile '%ZIP%'"
  )
  powershell -NoProfile -ExecutionPolicy Bypass -Command "Expand-Archive -Path '%ZIP%' -DestinationPath '%DIST_DIR%' -Force"
)
call "%GRADLE_BIN%" %*
exit /b %ERRORLEVEL%
