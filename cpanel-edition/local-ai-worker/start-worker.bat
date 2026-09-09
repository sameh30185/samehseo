@echo off
setlocal
cd /d "%~dp0"
if not exist config.json (
  echo Copy config.example.json to config.json and edit it first.
  exit /b 1
)
where node >nul 2>&1
if errorlevel 1 (
  echo Node.js not found in PATH. Install Node 18+ then retry.
  exit /b 1
)
if exist worker.pid (
  for /f %%p in (worker.pid) do (
    tasklist /FI "PID eq %%p" 2>nul | find "%%p" >nul
    if not errorlevel 1 (
      echo Worker already running PID=%%p
      exit /b 0
    )
  )
  del /f /q worker.pid >nul 2>&1
)
echo Starting SAMEH Local AI Worker...
start "SAMEH-Local-AI-Worker" /MIN cmd /c "node worker.js"
rem worker.js writes worker.pid
timeout /t 2 /nobreak >nul
if exist worker.pid (
  for /f %%p in (worker.pid) do echo Started PID=%%p
) else (
  echo Warning: pid file not yet written — check worker.log
)
endlocal
