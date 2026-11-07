@echo off
title GradeQuest Student CBT
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0Launch-StudentCbt.ps1"
if %ERRORLEVEL% NEQ 0 (
    pause
)
