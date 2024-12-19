# IP Proxy Testing Script

This repository contains a batch script to automate the process of downloading, extracting, and testing a list of IP addresses through an HTTP proxy setup. The script performs HTTP checks on each IP and, if successful, further tests them using [Xray](https://github.com/XTLS/Xray-core) for proxy compatibility.

## Features

- **Automated Download**: Downloads a ZIP file containing lists of IPs from `https://zip.baipiao.eu.org`.
- **Extraction**: Extracts files from the ZIP archive to a `temp` folder.
- **HTTP and Proxy Testing**: Performs HTTP checks on each IP, and if the IP responds as expected, further tests it with Xray for proxy compatibility.
- **CSV Logging**: Saves results to `results.csv` for easy analysis.
- **Temporary File Management**: Organizes temporary files in a `temp` folder and cleans up after execution.

## Requirements

- **Windows OS**
- [Xray](https://github.com/XTLS/Xray-core) installed and configured in the `bin` folder.
- [curl](https://curl.se/download.html) installed and accessible from the command line.
- [7-Zip](https://www.7-zip.org/) for ZIP file extraction.

## Setup

1. **Clone the repository**:
   ```bash
   git clone https://github.com/XRaySup/ProxyIP.git
   cd ProxyIP
## Configure the Directory Structure

- Place the `xray.exe` file in the `bin` folder.
- Ensure `curl` is installed and accessible from the command line.
- Ensure `7z.exe` is installed and accessible (e.g., add it to the system `PATH`).



## Usage

### Run the Script:

Run the batch script from the command line:

```bash
script.bat
```

### Input and Testing:

- The script downloads a ZIP file of IPs, extracts files with names ending in `-443.txt`, and performs tests on each IP.
- For each IP:
  - Checks if it responds with the expected message on HTTP.
  - If successful, tests the IP through Xray to see if it provides a `204 No Content` response.
  - Logs each result in `results.csv` with the IP address and response code.

### Output

#### `results.csv`:

- The results of each IP test are saved in this CSV file, with columns for the IP address and response code.

