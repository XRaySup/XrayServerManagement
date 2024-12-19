@echo off
setlocal enabledelayedexpansion

:: Define paths
set "BIN_DIR=bin"
set "TEMP_DIR=temp"
set "ZIP_FILE=%TEMP_DIR%\downloaded.zip"
set "EXTRACT_DIR=%TEMP_DIR%\extracted"
set "OUTPUT_CSV=results.csv"
set "VALIDIPS_CSV=ValidIPs.csv"
set "XRAY_EXECUTABLE=%BIN_DIR%\xray.exe"
set "XRAY_CONFIG_FILE=%BIN_DIR%\config.json"
set "TEMP_CONFIG_FILE=%TEMP_DIR%\temp_config.json"
set "CURL_OUTPUT=%TEMP_DIR%\_check.txt"
set "fileSize=102400"

:: Ensure the temp and extracted directories exist
if not exist "%TEMP_DIR%" mkdir "%TEMP_DIR%"
if not exist "%EXTRACT_DIR%" mkdir "%EXTRACT_DIR%"

:: Create or clear the output CSV file
if exist %VALIDIPS_CSV% del %VALIDIPS_CSV%
echo IP,HTTP Check,Xray Check,Download Time "ms",Download Size "Bytes" > "%OUTPUT_CSV%"

:: Check if an IP address or CSV file is provided as an argument
if "%~1" neq "" (
    if exist "%~1" (
        set "CSVFILE=%~1"
        echo Processing IPs from CSV file: !CSVFILE!
        
        for /f "tokens=1 delims=," %%i in ('type "!CSVFILE!"') do (
            set "IPADDR=%%i"
            call :process_ip !IPADDR!
        )
    ) else (
        set "IPADDR=%~1"
        call :process_ip !IPADDR!
    )
) else (
    :: Download the ZIP file from the specified URL
    echo Downloading ZIP file from https://zip.baipiao.eu.org...
    curl -sLo "%ZIP_FILE%" "https://zip.baipiao.eu.org"

    :: Extract ZIP file to the extraction directory
    echo Extracting ZIP file...
    powershell -command "Expand-Archive -Path '%ZIP_FILE%' -DestinationPath '%EXTRACT_DIR%' -Force"
    
    :: Loop through each file with "-443.txt" in the extraction directory
    for %%f in ("%EXTRACT_DIR%\*-443.txt") do (
        echo Processing file: %%f

        :: Loop through each line (IP) in the file
        for /f "usebackq delims=" %%i in (%%f) do (
            set "IPADDR=%%i"
            call :process_ip !IPADDR!
        )
    )
    echo Done. Results saved in %OUTPUT_CSV%.
)

pause
exit /b

:process_ip
set "IPADDR=%~1"
echo Checking IP: !IPADDR!

:: Check the IP over HTTP on port 443 (timeout after 3 seconds)
for /f %%j in ('curl -s -m 1 -o nul -w "%%{http_code}" http://!IPADDR!:443') do (
    set "HTTP_CHECK=%%j"
)

:: If HTTP check returns "400", perform Xray check
if "!HTTP_CHECK!"=="400" (
    echo IP !IPADDR! passed HTTP check. Starting Xray check...

    :: Encode IP in Base64 format
    for /f "tokens=*" %%k in ('powershell -command "[Convert]::ToBase64String([System.Text.Encoding]::UTF8.GetBytes('!IPADDR!'))"') do (
        set "BASE64IP=%%k"
    )

    :: Update the Xray config with the Base64 IP
    powershell -command "(Get-Content -Path '%XRAY_CONFIG_FILE%') -replace 'PROXYIP', '!BASE64IP!' | Set-Content -Path '%TEMP_CONFIG_FILE%'"

    :: Run Xray in the background and perform 204 check
    start "" /b "%XRAY_EXECUTABLE%" run -config "%TEMP_CONFIG_FILE%"
    timeout /t 1 /nobreak > nul

    :: Perform the 204 No Content check via Xray proxy
    for /f %%m in ('curl -s -m 1 -o nul -w "%%{http_code}" --proxy http://127.0.0.1:8080 https://cp.cloudflare.com/generate_204') do (
        set "XRAY_CHECK=%%m"
    )

    if "!XRAY_CHECK!"=="204" (
        echo 204 Check Response is: !XRAY_CHECK!
        :: Download Test
        powershell -command "& {curl.exe -s -w \"TIME: %%{time_total}\" --proxy http://127.0.0.1:8080 https://speed.cloudflare.com/__down?bytes=%fileSize% --output %TEMP_DIR%\temp_downloaded_file}" > %TEMP_DIR%\temp_output.txt

        :: Extract the download time from the output file
        set "downTimeMil=0"
        for /f "tokens=2 delims=:" %%k in ('findstr "TIME" %TEMP_DIR%\temp_output.txt') do set "downTimeMil=%%k"
        if "!downTimeMil!"=="" set "downTimeMil=0"

        :: Check if the downloaded file size matches the requested size
        for /f %%s in ('powershell -command "(Get-Item %TEMP_DIR%\temp_downloaded_file).length"') do set "actualFileSize=%%s"

        if "!actualFileSize!"=="%fileSize%" (
            echo Downloaded file size matches the requested size.
            echo !IPADDR! >> "%VALIDIPS_CSV%"
        ) else (
            echo Warning: Downloaded file size does not match the requested size.
        )

        :: Convert the floating-point download time to an integer (milliseconds)
        for /f %%m in ('powershell -command "[math]::Round(!downTimeMil! * 1000)"') do (
            set "downTimeMilInt=%%m"
        )
        echo "Converted Download Time (ms):" !downTimeMilInt!

        :: Record result in CSV
        echo !IPADDR!,!HTTP_CHECK!,!XRAY_CHECK!,!downTimeMilInt!,!actualFileSize! >> "%OUTPUT_CSV%"

        :: Clean up temporary file
        if exist %TEMP_DIR%\temp_downloaded_file del %TEMP_DIR%\temp_downloaded_file
    ) else (
        echo !IPADDR!,!HTTP_CHECK!,!XRAY_CHECK!,-,- >> "%OUTPUT_CSV%"
    )

    :: Stop Xray process
    taskkill /f /im xray.exe > nul 2>&1
) else (
    echo !IPADDR!,!HTTP_CHECK!,-,-,- >> "%OUTPUT_CSV%"
)
exit /b