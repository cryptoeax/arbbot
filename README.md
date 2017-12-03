# Bitcoin/Altcoin Arbitrage Trading Bot
The bot checks for altcoins, which are traded on both Poloniex and Bittrex and compares their prices. If the difference is big enough to earn at least some satoshis (after deducting transfer fees), it enters the trade. The bot records its activities and exchange rates in a database and uses this information to reinvest a portion of its profits into the most profitable (in terms of arbitragable) altcoin. Additionally, the amount of held altcoins is calculated based on their exchange rate, transfer fees and times. This allows the bot to profit from hyped (PnD) coins.

This bot is a fork of the original 1.0 version of the [upstream bot](https://github.com/opencryptotrader/arbbot) but has been improved significantly both in the backend and in the UI since the original release.

You can see the bot running on a cheap [linode](https://www.linode.com) here:
![](https://screenshots.firefoxusercontent.com/images/64c6a25c-d733-46fa-acb9-25f3e002b124.png)

You can see the upstream bot for comparison running on a $5 Digital Ocean VPS here: http://178.17.174.178/

![](https://i.imgur.com/XcmnfGt.png)

## Installation on Debian 9.0+ / Ubuntu 16.04+

Install required packages:

```
apt-get install php-cli php-curl php-mysqlnd mysql-server nginx-full php-fpm unzip
```

Download the archive to the server and extract it:

```
cd /var/www
wget https://github.com/cryptoeax/arbbot/archive/production.zip && unzip arbitrage-bot.zip
```

cd into the directory:

```
cd arbitrage-bot
```

Prepare the MySQL database:

```
  mysql -u root -p
  mysql> CREATE DATABASE arbitrage;
  mysql> GRANT ALL ON arbitrage.* TO arbitrage@localhost IDENTIFIED BY 'YOUR_PASSWORD';
  mysql> use arbitrage;
  mysql> source database.sql;
  mysql> quit
  ```

Configure the database connection:

```
cp web/config.inc.php.example web/config.inc.php
nano web/config.inc.php
```


Configure the bot:

```
cp config.ini.example config.ini
nano config.ini
```

Edit all options to fit your needs and enter your API keys! You can change settings even while the bot is running. The changes will be automatically applied.

## Configuring the Webinterface

```
rm /etc/nginx/sites-enabled/default
nano /etc/nginx/sites-enabled/default
```

The NGINX configuration file should look like this:

```
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
```

restart the webserver:

```
/etc/init.d/nginx restart
```

You should now be able to access the webinterface with your browser.

## Running the bot

Now you are ready to give the bot a test by running it:

```
php main.php
```

(Note: It is recommended to run `main.php` with `[hhvm](https://docs.hhvm.com/hhvm/installation/linux)` instead of `php` in order to speed up the bot a bit.)

You should see output like this:

```
19:13:34: ARBITRATOR V2.0 launching

```
To actually allow the bot to buy coins automatically, you need to update the database. Use the commands below OR
navigate to http://YOUR_IP/adminer.php, click 'select stats' and edit the autobuy_funds field.
```
  mysql -u root -p
  mysql> use arbitrage;
  mysql> UPDATE stats SET value = "0.2" WHERE keyy = "autobuy_funds";
  mysql> quit
```

This example assigns 0.2 BTC to the "autobuy_funds". The higher the amount, the more coins can be bought and
the more arbitrage-opportunities can be taken. Be careful to keep at least 0.1 - 0.2 BTC at the exchange to give
the bot enough room to trade.

## How does the bot make profit?

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
You will notice some messages like `TRADE IS NOT PROFITABLE WITH AVAILABLE FUNDS` in the log. This means
that the bot has noted in its database that this coin can be traded with. During the next buying cycle, it
will consider buying this coin.

## Release History
* 2.0
    * Improved the web-based UI, charts, tooltips to help new users, legends
    * Added a P&L section in the UI to display detailed information about the incurred profits and losses as a result of trades, including daily and per-coin charts with filtering options.
    * Added an Alerts section in the UI to display the stuck withdrawal alerts in the UI.
    * Optimized the bot to enable it to make up to 4 times more trades.
    * Optimized the accuracy of the bot to enable it to run at a profitable trade percentage of above 90%.
    * Several bug fixes and improvements to the stability of the bot.
* 1.0
    * Complete rewrite
    * Support for multiple exchanges, more automation and more configuration options
    * Initial public release
* 0.6
    * fixed possible incorrect calculation of order rates
* 0.5
    * removed default API key for mandrill and added configuration option
    * using sendmail if mail address is set but mandrill is disabled
    * added a description on how the bot works to the readme
    * made stray orders cancellation optional
* 0.4
    * added cancellation of stray orders
    * fixed a critical bug which caused the bot to sometimes load the wrong stats for a coin
* 0.3
    * increased timeouts for public API calls
    * added new config option 'min-profit'
    * added new config option 'altcoin-balance-factor'
    * improved some error messages to prevent confusion
    * using default settings when some config options are missing
    * some fixes to treat USDE and USDe as the same coin
    * corrected trading fees for Bittrex
    * decreased required use-amount before considering a coin for buying
    * changed timezone setting to UTC
    * some changes for compatibility with older PHP versions
* 0.2
    * moved exchange fees to new file 'fees.json'
    * added Mandrill to simplify sending of emails
    * bugfix to prevent endless loop if 'stats' table is empty
    * tweaked timeouts and exchange query rates
    * added comments to improve code readability
    * removed unused code
* 0.1
    * Initial limited public release
