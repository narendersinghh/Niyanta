; Niyanta Tracker NSIS hooks (package.json build.nsis.include). Registers the niyanta-tracker:// scheme ("Sign in with your
; browser" links) for the installing user (perMachine is false, so SHCTX is HKCU). The app registers the same key at start
; (app.setAsDefaultProtocolClient); the uninstaller removes it, except while an update replaces the old version.
!macro customInstall
  WriteRegStr SHCTX "Software\Classes\niyanta-tracker" "" "URL:Niyanta Tracker"
  WriteRegStr SHCTX "Software\Classes\niyanta-tracker" "URL Protocol" ""
  WriteRegStr SHCTX "Software\Classes\niyanta-tracker\DefaultIcon" "" "$appExe,0"
  WriteRegStr SHCTX "Software\Classes\niyanta-tracker\shell\open\command" "" '"$appExe" "%1"'
!macroend

!macro customUnInstall
  ${ifNot} ${isUpdated}
    DeleteRegKey SHCTX "Software\Classes\niyanta-tracker"
  ${endIf}
!macroend
