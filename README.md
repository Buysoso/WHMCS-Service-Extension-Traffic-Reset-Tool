# WHMCS Service Extension & Traffic Reset Tool

[![PHP](https://img.shields.io/badge/PHP-7.4%2B-blue)](https://www.php.net/)
[![WHMCS](https://img.shields.io/badge/WHMCS-Compatible-green)](https://www.whmcs.com/)

A robust CLI utility for WHMCS administrators to bulk extend service due dates and reset bandwidth usage for specific product packages. Ideal for handling service compensations or SLA adjustments.

## Features

- **Bulk Processing**: Efficiently handles large numbers of services using memory-safe chunking.
- **Date Extension**: Automatically extends both `Next Due Date` and `Next Invoice Date`.
- **Traffic Reset**: Optional bandwidth usage reset via standard WHMCS API (`ModuleUnsuspend`).
- **Dry Run Mode**: Safe simulation mode to preview changes without modifying data.
- **Environment Check**: Includes a diagnostic script to verify WHMCS environment health.
- **Robust Error Handling**: Skips invalid dates and continues processing even if individual items fail.

## Requirements

- WHMCS 7.x or 8.x
- PHP CLI access
- Administrator account with API access permissions

## Installation

1. Clone or download this repository.
2. Place the scripts in your WHMCS root directory (or a subdirectory, e.g., `crons/AddTime/`).
   - The script automatically attempts to locate `init.php` in the current or parent directories.

## Usage

Run the script from the command line:

```bash
php AddTime.php [options]
```

### Options

| Option | Description | Default |
|--------|-------------|---------|
| `--package_id` | The ID of the product/service to process (Required) | `1` |
| `--days` | Number of days to extend (Positive integer) | `10` |
| `--reset_traffic` | Whether to reset bandwidth (`on`/`off` or `true`/`false`) | `true` |
| `--dry-run` | Enable simulation mode (No changes made) | `false` |

### Examples

**1. Simulation (Dry Run) - Recommended First Step**
Preview which services will be affected and verify date calculations:
```bash
php AddTime.php --package_id=47 --days=30 --dry-run
```

**2. Standard Execution**
Extend services for Package ID 47 by 30 days and reset traffic:
```bash
php AddTime.php --package_id=47 --days=30
```

**3. Extend Only (No Traffic Reset)**
```bash
php AddTime.php --package_id=47 --days=7 --reset_traffic=off
```

**4. Check Environment**
Verify database connection and admin user availability:
```bash
php TestEnv.php
```

## How It Works

1. **Initialization**: Loads WHMCS environment (`init.php`).
2. **Configuration**: Parses command-line arguments.
3. **Safety Check**: If `--dry-run` is active, it enters read-only mode.
4. **Query**: Fetches all **Active** services matching the `package_id`.
5. **Processing**:
   - Calculates new due dates based on the current `nextduedate`.
   - Optionally calls `ModuleUnsuspend` API to trigger traffic reset on the remote server.
   - Updates `tblhosting` database table with new dates.
6. **Logging**: Outputs real-time status for each service ID.

## Disclaimer

This script modifies your WHMCS database directly. While it includes safety mechanisms (Dry Run, Error Catching), **always backup your database** before running bulk operations in a production environment.

## License

MIT License
