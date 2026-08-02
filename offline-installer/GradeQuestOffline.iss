#define MyAppName "GradeQuest Offline CBT Server"
#define MyAppVersion "1.0.0"
#define MyAppPublisher "GradeQuest"
#define MyAppExeName "GradeQuestOffline"

[Setup]
AppId={{7C58B6C0-1F9D-4F0C-8FE5-91A6E8B35C2A}
AppName={#MyAppName}
AppVersion={#MyAppVersion}
AppPublisher={#MyAppPublisher}
DefaultDirName={sd}\GradeQuestOfflineCBT
DefaultGroupName=GradeQuest Offline CBT Server
DisableProgramGroupPage=yes
OutputDir=dist
OutputBaseFilename=GradeQuestOfflineCBTSetup
SetupIconFile=assets\gradequest.ico
WizardImageFile=assets\gradequest-installer-large.bmp
WizardSmallImageFile=assets\gradequest-installer-small.bmp
InfoBeforeFile=assets\welcome.txt
InfoAfterFile=assets\readme.txt
Compression=lzma
SolidCompression=yes
WizardStyle=modern
PrivilegesRequired=admin
ArchitecturesAllowed=x64
ArchitecturesInstallIn64BitMode=x64
UninstallDisplayIcon={app}\assets\gradequest.ico
SetupLogging=yes

[Languages]
Name: "english"; MessagesFile: "compiler:Default.isl"

[Files]
Source: "dist\GradeQuestOfflinePayloadFast\*"; DestDir: "{app}"; Flags: ignoreversion recursesubdirs createallsubdirs
Source: "assets\gradequest.ico"; DestDir: "{app}\assets"; Flags: ignoreversion
Source: "assets\gradequest-installer-small.bmp"; DestDir: "{app}\assets"; Flags: ignoreversion

[Dirs]
Name: "{app}"; Permissions: users-modify
Name: "{app}\logs"; Permissions: users-modify
Name: "{app}\run"; Permissions: users-modify
Name: "{app}\data"; Permissions: users-modify
Name: "{app}\app\backend\storage"; Permissions: users-modify

[Icons]
Name: "{autoprograms}\GradeQuest Offline CBT Server\Start Server"; Filename: "powershell.exe"; Parameters: "-ExecutionPolicy Bypass -File ""{app}\scripts\Start-GradeQuestOffline.ps1"""; WorkingDir: "{app}"; IconFilename: "{app}\assets\gradequest.ico"
Name: "{autoprograms}\GradeQuest Offline CBT Server\Stop Server"; Filename: "powershell.exe"; Parameters: "-ExecutionPolicy Bypass -File ""{app}\scripts\Stop-GradeQuestOffline.ps1"""; WorkingDir: "{app}"; IconFilename: "{app}\assets\gradequest.ico"
Name: "{autoprograms}\GradeQuest Offline CBT Server\Open Offline CBT"; Filename: "powershell.exe"; Parameters: "-ExecutionPolicy Bypass -File ""{app}\scripts\Open-OfflineCbt.ps1"""; WorkingDir: "{app}"; IconFilename: "{app}\assets\gradequest.ico"
Name: "{autoprograms}\GradeQuest Offline CBT Server\Show Server Address"; Filename: "powershell.exe"; Parameters: "-ExecutionPolicy Bypass -File ""{app}\scripts\Show-ServerAddress.ps1"""; WorkingDir: "{app}"; IconFilename: "{app}\assets\gradequest.ico"
Name: "{autodesktop}\GradeQuest Offline CBT"; Filename: "powershell.exe"; Parameters: "-ExecutionPolicy Bypass -File ""{app}\scripts\Open-OfflineCbt.ps1"""; WorkingDir: "{app}"; Tasks: desktopicon; IconFilename: "{app}\assets\gradequest.ico"

[Tasks]
Name: "desktopicon"; Description: "Create desktop shortcuts"; GroupDescription: "Additional shortcuts:"

[Run]
Filename: "powershell.exe"; Parameters: "-ExecutionPolicy Bypass -File ""{app}\scripts\Initialize-GradeQuestOffline.ps1"" -InstallDir ""{app}"""; StatusMsg: "Initializing GradeQuest Offline CBT database..."; Flags: runhidden waituntilterminated
Filename: "powershell.exe"; Parameters: "-ExecutionPolicy Bypass -File ""{app}\scripts\Start-GradeQuestOffline.ps1"" -InstallDir ""{app}"""; Description: "Start GradeQuest Offline CBT Server"; Flags: postinstall nowait skipifsilent
Filename: "powershell.exe"; Parameters: "-ExecutionPolicy Bypass -File ""{app}\scripts\Open-OfflineCbt.ps1"""; Description: "Open Offline CBT"; Flags: postinstall nowait skipifsilent

[UninstallRun]
Filename: "powershell.exe"; Parameters: "-ExecutionPolicy Bypass -File ""{app}\scripts\Stop-GradeQuestOffline.ps1"" -InstallDir ""{app}"""; Flags: runhidden waituntilterminated
