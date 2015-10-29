# CLI Automatic Updater for Joomla!

This script can be used to perform automatic updates of Joomla! core and extensions.

## Installation

Add autoupdate.php to the cli folder of a joomla installation.

## Usage

    cd /path/to/cli
    php autoupdate.php

## Flags

    Operations

    -f, --fetch             Run Fetch
    -u, --update            Run Update
    -l, --list              List Updates
    -x, --export            Export Updates JSON

    Update Filters

    -i, --id ID             Update ID
    -a, --all               All Packages
    -V, --version VER       Version Filter
    -c, --core              Joomla! Core Packages
    -e, --extension LOOKUP  Extension by ID/NAME
    -t, --type VAL          Type

    Additional Flags
    
    -v, --verbose           Verbose
