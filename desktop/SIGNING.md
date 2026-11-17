# Signing, SmartScreen and Defender (zero-spend plan)

Niyanta Tracker ships without a paid certificate. This page says what that costs, what is done for free, and what to do
for each release.

| Problem | Free fix | Only fixed by paying |
|---|---|---|
| macOS asks for Screen Recording again after every update | Sign every build with the project's own self-signed certificate (below) | |
| macOS "unverified developer" on the first open of a browser download | The one-line `curl ... \| bash` install (no quarantine flag); otherwise Privacy & Security, Open Anyway | Apple Developer ID + notarization |
| Windows SmartScreen "Windows protected your PC" | Explained on Time Tracking (More info, Run anyway). Reputation grows per file as people run it | Code-signing certificate |
| Microsoft Defender deletes or blocks a new version | Submit each release as a false positive (checklist below) | Signing makes this much rarer |
| Windows 11 Smart App Control blocks the setup | None for a direct download (Smart App Control allows only signed or known apps). The Microsoft Store package is the free route | Code-signing certificate |

## macOS: one certificate for every build (free)

macOS ties the Screen Recording permission to the app's *designated requirement*. An ad-hoc signature's requirement is the
hash of that one build, so each update looks like a new app. Signed with the same certificate, every version has

    identifier "app.niyanta.tracker" and certificate ... = H"<sha1 of the certificate>"

and the permission stays through updates.

1. Once, on any Mac or Linux computer with `openssl`: `bash desktop/scripts/make-mac-cert.sh` (it writes to
   `~/niyanta-mac-cert` unless you pass a folder). Keep that folder private and backed up (a password manager); never
   commit it.
2. Add the two repository secrets it prints (GitHub > Settings > Secrets and variables > Actions):
   `MAC_SELF_CERT_P12` (the base64 file) and `MAC_SELF_CERT_PASSWORD`.
3. Release as usual. The macOS job imports the certificate into a temporary keychain, `scripts/adhoc-sign.js` signs the
   app with it and fails the build if the designated requirement is not tied to that certificate, and the step *Check the
   macOS code identity* prints it. The keychain is deleted at the end of the job.

People allow Screen Recording once more for the first release signed this way (the identity changes from ad hoc to the
certificate); after that it is kept. If the certificate is ever replaced, the same happens once. The app handles both
cases itself (`src/main/permfix.js`): it reads its own designated requirement at start and resets its Screen Recording
entry (tccutil, own bundle id) only when the identity changed or a capture fails while macOS says "granted".

Without the secrets the build falls back to the ad-hoc signature, as before. A self-signed certificate does not remove
Gatekeeper's "unverified developer" prompt for browser downloads; only a Developer ID with notarization does.

**Do not use a self-signed certificate on Windows.** SmartScreen, Smart App Control and Defender treat a self-signed
signature like no signature at all (or worse), so the Windows build stays unsigned unless a real certificate is configured.

## Windows: what the build does for free

- The Time Tracking page offers a plain browser download as the main way, with the SmartScreen steps. The PowerShell
  alternative is two visible steps (save to Downloads with `Invoke-WebRequest`, check the sha512, `Start-Process`); the
  page no longer offers `irm ... | iex`, the download-cradle pattern that AMSI, Defender and EDR products flag. The old
  `install.ps1` still answers for commands copied earlier, and stops in an administrator window of another Windows account
  (the per-user setup would land in that account).
- No behaviour that antivirus rules associate with malware: no scripts, `cmd.exe`, PowerShell, `reg.exe` or scheduled
  tasks from the app; nothing started from `%TEMP%`; updates are downloaded and installed only when the person clicks
  *Update now* (no background download, no install on quit); *Uninstall* opens the normal uninstaller window (never
  silent); no keyboard or mouse hooks (activity is the system idle time); screenshots only through Electron's
  `desktopCapturer`, only after consent, with the tray icon and the capture notice; per-user install, no administrator
  rights. `scripts/security-check.js` keeps it that way.
- Complete version information on the app and the setup (company "Niyanta", product, description, copyright, version,
  original file name); the workflow fails a build where something is missing.

## Each release: Microsoft Defender submission (free, about 10 minutes)

Defender learns from submissions; a new version is a new file to it.

1. When the release workflow is done, download the setup `niyanta-tracker-<version>-win-x64.exe` from the GitHub release.
   The SHA-256 of every file is in the build log (step *List output*).
2. Install it on a Windows PC and take the app file too:
   `%LOCALAPPDATA%\Programs\niyanta-tracker\Niyanta Tracker.exe`.
3. Open https://www.microsoft.com/en-us/wdsi/filesubmission, sign in with a Microsoft account, choose
   **Software developer**, then **Microsoft Defender Antivirus (Windows 10/11)**.
4. Submit the setup, then the app file (one submission each, zip them if the form asks): product "Niyanta Tracker",
   company "Niyanta", detection: **Incorrectly detected as malware/malicious** (give the detection name if a PC reported
   one, e.g. from Windows Security > Protection history), and a note such as "Employee time tracker built with Electron,
   installed per user from our own server; screenshots only with the user's consent."
5. Microsoft answers by e-mail, usually within a few days. Once cleared, PCs pick it up with the next definition update
   (`"%ProgramFiles%\Windows Defender\MpCmdRun.exe" -SignatureUpdate`, or Windows Security > Virus & threat protection
   updates > Check for updates).

## Microsoft Store (free route around SmartScreen and Smart App Control)

Apps from the Microsoft Store are signed by Microsoft, so SmartScreen, Smart App Control and most Defender heuristics
accept them. electron-builder can build the Store package (`.appx`) from the same app.

- Registration: Microsoft announced free Store registration for individual developers in 2025 (company accounts had a
  one-time fee). Check the current terms at https://storedeveloper.microsoft.com before relying on it.
- Setup: in Partner Center reserve the name "Niyanta Tracker", then from *Product identity* copy *Package/Identity/Name*
  and *Package/Identity/Publisher* (`CN=...`) into the repository variables `MS_STORE_IDENTITY_NAME` and
  `MS_STORE_PUBLISHER`. The Windows job then also builds `niyanta-tracker-<version>-win-x64.appx` (workflow artifact
  *niyanta-tracker-microsoft-store*, after the normal release upload; it can never fail or delay the NSIS release).
  Upload it in Partner Center; certification takes a few days. The Store listing must describe the monitoring (time
  tracking, activity levels, screenshots with consent) and link a privacy policy.
- In the app: a Store install recognises itself (`process.windowsStore`); updates then come from the Store (the update
  button opens the Store) and *Uninstall* falls back to Settings > Apps. Launch at startup needs a Store startup task,
  which is not configured yet, so people start it themselves or pin it. Untested end to end: try a private (hidden)
  Store listing first.
- A Store listing is public unless marked private/hidden; people still connect it to their own workspace.

**winget** needs a public, permanent download URL for every version (the community manifests point at it). The installers
are only served by each workspace behind a sign-in or a personal key, so winget does not fit today.

**SignPath Foundation** gives free Windows code signing to open-source projects. If the tracker is ever published as open
source, apply there; then set the signing up in `.github/workflows/desktop.yml` like the Azure option.

## Optional paid signing (not needed for the plan above)

The workflow still signs when these secrets exist, and does nothing without them: Windows Azure Trusted Signing (secrets
`AZURE_TENANT_ID`, `AZURE_CLIENT_ID`, `AZURE_CLIENT_SECRET`, variables `AZURE_TRUSTED_SIGNING_ENDPOINT`,
`AZURE_TRUSTED_SIGNING_ACCOUNT`, `AZURE_TRUSTED_SIGNING_PROFILE`) or a `.pfx` (`WIN_CSC_LINK`, `WIN_CSC_KEY_PASSWORD`);
macOS Developer ID with notarization (`MAC_CSC_LINK`, `MAC_CSC_KEY_PASSWORD`, `APPLE_ID`, `APPLE_APP_SPECIFIC_PASSWORD`,
`APPLE_TEAM_ID`). A Developer ID takes precedence over the self-signed certificate.
