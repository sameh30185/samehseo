@echo off
setlocal
cd /d "%~dp0"
if not exist worker.pid (
  echo STATUS=offline
  exit /b 1
)
set /p PID=<worker.pid
tasklist /FI "PID eq %PID%" 2>nul | find "%PID%" >nul
if errorlevel 1 (
  echo STATUS=offline
  del /f /q worker.pid >nul 2>&1
  exit /b 1
)
echo STATUS=online PID=%PID%
if exist worker.log (
  echo --- last log lines ---
  powershell -NoProfile -Command "Get-Content -Path 'worker.log' -Tail 8"
)
endlocal
