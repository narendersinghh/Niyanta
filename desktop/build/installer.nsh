; Niyanta Tracker NSIS hooks (package.json build.nsis.include). Only conventional per-user installer steps, no scripts and
; nothing started from a temporary folder:
; - registers the niyanta-tracker:// scheme ("Sign in with your browser" links) for the installing user (perMachine is
;   false, so SHCTX is HKCU). The app registers the same key at start (app.setAsDefaultProtocolClient).
; - on a real uninstall (not while an update replaces the old version) removes that key and the update download cache
;   (%LOCALAPPDATA%\niyanta-tracker-updater). The app data (%APPDATA%\Niyanta Tracker) is removed only when asked for with
;   electron-builder's own --delete-app-data, which the app's Settings › Uninstall passes (src/main/uninstall.js); a
;   reinstall from Settings › Apps keeps the person's preferences and sign-in.
!macro customInstall
  WriteRegStr SHCTX "Software\Classes\niyanta-tracker" "" "URL:Niyanta Tracker"
  WriteRegStr SHCTX "Software\Classes\niyanta-tracker" "URL Protocol" ""
  WriteRegStr SHCTX "Software\Classes\niyanta-tracker\DefaultIcon" "" "$appExe,0"
  WriteRegStr SHCTX "Software\Classes\niyanta-tracker\shell\open\command" "" '"$appExe" "%1"'
!macroend

!macro customUnInstall
  ${ifNot} ${isUpdated}
    DeleteRegKey SHCTX "Software\Classes\niyanta-tracker"
    RMDir /r "$LOCALAPPDATA\niyanta-tracker-updater"
  ${endIf}
!macroend
