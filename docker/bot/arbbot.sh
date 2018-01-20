#!/bin/bash

/bin/wait-for-db.sh current_simulated_profit_rate "$1" "$2" /usr/bin/hhvm main.php