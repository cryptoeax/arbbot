ARBITRAGE BOT BITTREX <-> POLONIEX
==================================
Readme for Bot v1

0. SYSTEM REQUIREMENTS
------------------
512 MB or more of RAM
1 x 2.0 GHz or faster CPU
Debian GNU/Linux 9.0 or later / Ubuntu Linux 16.04 or later

For example, the live demo runs on Digital Ocean's $5/mo cloud VPS.

1. PREREQUISITES
------------------
The bot requires a PHP interpreter, a MySQL database and a webserver.

DEBIAN / UBUNTU:

Install required packages:

  $ apt-get update && apt-get install php-cli php-curl php-mysqlnd mysql-server nginx-full php-fpm unzip nano screen

2. PREPARE THE BOT
------------------

Download the archive to the server and extract it:

  $ cd /var/www
  $ wget http://178.17.174.178/arbitrage-bot.zip && unzip arbitrage-bot.zip

cd into the directory:

  $ cd arbitrage-bot

Prepare the MySQL database:

  $ mysql -u root -p
  mysql> CREATE DATABASE arbitrage;
  mysql> GRANT ALL ON arbitrage.* TO arbitrage@localhost IDENTIFIED BY 'YOUR_PASSWORD';
  mysql> use arbitrage;
  mysql> source database.sql;
  mysql> quit

Configure the database connection:

  $ cp web/config.inc.php.example web/config.inc.php
  $ nano web/config.inc.php


Configure the bot:

  $ cp config.ini.example config.ini
  $ nano config.ini

Edit all options to fit your needs and enter your API keys!

---------------------------------------

You can change settings even while the bot is running. The changes will be automatically applied.

Configure the webinterface:
  $ rm /etc/nginx/sites-enabled/default
  $ nano /etc/nginx/sites-enabled/default

The configuration file should look like this:

---------------------------------------

server {

        listen 80;
        root /var/www/arbitrage-bot/web;
        index index.html;
        server_name localhost;

        location / {

                try_files $uri $uri/ /index.html;

        }

        location ~ \.php$ {

          include snippets/fastcgi-php.conf;
          fastcgi_pass unix:/var/run/php/php7.0-fpm.sock;

        }

}

---------------------------------------

restart the webserver:

  $ /etc/init.d/nginx restart

You should now be able to access the webinterface with your browser.

3. RUN THE BOT
--------------

Now you are ready to give the bot a test by running it:

  $ php main.php

You should see output like this:

19:13:34: ARBITRATOR V1.0 launching

HINT: When you have verified that the bot works, use screen to run the bot on the background:

  $ screen php main.php

4. LET THE BOT DO THE WORK
--------------------------

Arbitrage trading means that differences in exchange rates between two exchanges are used to gain a profit.
These opportunities usually exist only for a few seconds. It is important to act fast when such an opportunity
is detected.

If you would buy the altcoin as soon as a price difference is detected and send it to the other exchange to
sell it, you would most likely make a loss with this trade. Transfering coins usually takes 15 to 60 minutes.
Enough time for the exchange rates to equalize.

To make a profit from price differences you must deposit the altcoin at the exchange with the higher
sell rates (BID) and some BTC at the exchange with the higher buy rates (ASK).

Lets take BTCD as an example:

At Bittrex the current sell rate (BID) is 0.00395000 BTC
At Poloniex the current buy rate (ASK) is 0.00382098 BTC

This means a Spread of 0.00012902 BTC

The bot sells BTCD at Bittrex for 0.00395000 BTC each and instantly buys the same amount at Poloniex
for 0.00382098 BTC each. After the trade there is no change in the amount of BTCD. However, there is a
profit of 0.00012902 BTC per traded BTCD.

The BTCD are now being transfered from Poloniex to Bittrex and can then be used for trading.

It wouldn't be very easy to buy all those altcoins for the bot. Additionally, some of them have such low
volumes that there is rarely a trading opportunity. But you can delegate this job to the bot!

The bot will monitor the orderbooks of both exchanges and will decide which coins are worth to trade with.
You will notice some messages like "TRADE IS NOT PROFITABLE WITH AVAILABLE FUNDS" in the log. This means
that the bot has noted in its database that this coin can be traded with. During the next buying cycle, it
will consider buying this coin.

To actually allow the bot to buy coins automatically, you need to update the database. Use the commands below OR
navigate to http://YOUR_IP/adminer.php, click 'select stats' and edit the autobuy_funds field.

  $ mysql -u root -p
  mysql> use arbitrage;
  mysql> UPDATE stats SET value = "0.2" WHERE keyy = "autobuy_funds";
  mysql> quit

This example assigns 0.2 BTC to the "autobuy_funds". The higher the amount, the more coins can be bought and
the more arbitrage-opportunities can be taken. Be careful to keep at least 0.1 - 0.2 BTC at the exchange to give
the bot enough room to trade.

ATTENTION: This initial phase can take around 48h, depending on market conditions!
