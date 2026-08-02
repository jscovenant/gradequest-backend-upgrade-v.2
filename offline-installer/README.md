# GradeQuest Offline CBT Windows Installer

This folder contains the installer scaffold for a school LAN/offline CBT server.

The installer packages:

- Laravel backend
- Built React frontend
- Portable PHP runtime
- Portable MariaDB runtime
- Portable Nginx runtime
- Start/stop/open shortcuts
- Database initialization and migrations

## Required Runtime Folders

Place the portable runtimes here before building the installer:

```text
offline-installer/runtime/php/
offline-installer/runtime/mariadb/
offline-installer/runtime/nginx/
```

Expected executables:

```text
runtime/php/php.exe
runtime/php/php-cgi.exe
runtime/mariadb/bin/mysqld.exe
runtime/mariadb/bin/mysql.exe
runtime/nginx/nginx.exe
```

Do not commit the runtime binaries to GitHub. They are intentionally ignored.

## Build Flow

From the backend project root:

```powershell
powershell -ExecutionPolicy Bypass -File offline-installer/scripts/Prepare-Package.ps1 `
  -FrontendRoot "C:\Users\Administrator\Desktop\GradeQuest Upgrade\Gradequest V.2.1\gradequest-app-v2.1"
```

Then open Inno Setup and compile:

```text
offline-installer/GradeQuestOffline.iss
```

The compiled installer will create:

```text
C:\Program Files\GradeQuest Offline CBT Server
```

## School Usage

After installation, the school uses desktop/start-menu shortcuts:

- Start GradeQuest Offline Server
- Stop GradeQuest Offline Server
- Open Offline CBT
- Show Server Address

The school admin should download a fresh CBT bundle from the main GradeQuest portal and import it on the offline runner page before exam starts.
