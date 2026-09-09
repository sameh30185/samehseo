@echo off
setlocal
cd /d "%~dp0"
if not exist worker.pid (
  echo Worker offline (no pid file).
  exit /b 0
)
set /p PID=<worker.pid
echo Stopping PID tree %PID% ...
taskkill /PID %PID% /T /F >nul 2>&1
del /f /q worker.pid >nul 2>&1
echo Worker stopped.
endlocal
