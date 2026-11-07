#define MyAppName "GradeQuest Student CBT"
#define MyAppVersion "1.0.0"
#define MyAppPublisher "GradeQuest"

[Setup]
AppId={{C1D3B927-4610-4A3C-B970-F648D4C82E47}}
AppName={#MyAppName}
AppVersion={#MyAppVersion}
AppPublisher={#MyAppPublisher}
DefaultDirName={localappdata}\GradeQuestStudentCBT
DefaultGroupName={#MyAppName}
DisableProgramGroupPage=yes
OutputDir=dist
OutputBaseFilename=GradeQuestStudentClientSetup
SetupIconFile=assets\gradequest.ico
WizardStyle=modern
PrivilegesRequired=lowest
PrivilegesRequiredOverridesAllowed=dialog
UninstallDisplayIcon={app}\gradequest.ico
Compression=lzma2/max
SolidCompression=yes

[Languages]
Name: "english"; MessagesFile: "compiler:Default.isl"

[Files]
Source: "scripts\GradeQuest-Student-CBT.bat"; DestDir: "{app}"; Flags: ignoreversion
Source: "scripts\Launch-StudentCbt.ps1"; DestDir: "{app}"; Flags: ignoreversion
Source: "assets\gradequest.ico"; DestDir: "{app}"; Flags: ignoreversion

[Icons]
Name: "{userdesktop}\GradeQuest Student CBT"; Filename: "{app}\GradeQuest-Student-CBT.bat"; WorkingDir: "{app}"; IconFilename: "{app}\gradequest.ico"
Name: "{userprograms}\GradeQuest Student CBT"; Filename: "{app}\GradeQuest-Student-CBT.bat"; WorkingDir: "{app}"; IconFilename: "{app}\gradequest.ico"

[Run]
Filename: "{app}\GradeQuest-Student-CBT.bat"; Description: "Launch GradeQuest Student CBT now"; Flags: postinstall nowait skipifsilent unchecked
