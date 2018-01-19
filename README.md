# Arbitrator, A Bitcoin/Altcoin Arbitrage Trading Bot
The bot checks for altcoins, which are traded on both Poloniex and Bittrex and compares their prices. If the difference is big enough to earn at least some satoshis (after deducting transfer fees), it enters the trade. The bot records its activities and exchange rates in a database and uses this information to reinvest a portion of its profits into the most profitable (in terms of arbitragable) altcoin. Additionally, the amount of held altcoins is calculated based on their exchange rate, transfer fees and times. This allows the bot to profit from hyped (PnD) coins.

The original version of this bot had a [backdoor which was removed from this fork](https://github.com/cryptoeax/arbbot/commit/15abe54a462b9a6decb1ef2a197626b106c8e5d1) and the fork has gone through a security audit and to the extent of the knowledge of the current maintainer is free of any other security issues.  Furthermore, it currently has a few extra security features that prevent the web UI to be used in passwordless non-HTTPS Internet environments by default, which mitigates the original vulnerability that existed in the code.  The code base is kept small and simple for the purpose of making it possible for people to perform their own security audit should they choose so.  If you find any other bugs please file issues so that they can be fixed quickly!

You can see the bot running on a cheap [linode](https://www.linode.com) here.  Currently the bot is yielding daily profits of `7-20%` on a seed investment of around `0.4BTC`.
![](https://screenshots.firefoxusercontent.com/images/28c48837-caa2-4bc9-b987-7f891ac7eca6.png)

## Supported Exchanges

  * Bittrex (all BTC markets)
  * Bleutrade (all BTC markets)
  * Poloniex (all BTC markets)

## Running the bot using Docker

The only supported way to run this bot currently is to use Docker.  Advanced users are welcome to study the docker files to see how the configuration works if they want to setup their own advanced setups, but all such configurations are unsupported and you're on your own if things go wrong!

### Pre-requisites

Install the latest versions of docker and docker-compose

Clone the repository somewhere using `git clone --recursive`.

### Preparation

Customize your environment settings:

```
cp .env.example .env
vi .env               # edit the file to customize the variables, NEVER use the default passwords
vi config.ini         # edit the database settings to make them match .env
```

In case you have been running the bot from the pre-dockerized versions, you probably want to import the data that the bot currently has saved in its database into the database that the new containerized bot launches.  You can do so by planting a special `data.sql` file in the right place, like this:

```
mysqldump -h host -u username -p --no-create-info > docker/db/seed/data.sql
```

Once you have finished the above steps, you are ready to build your containers.

```
docker-compose build
```

### Running the bot

Now you are ready to give the bot a test by running it:

```
docker-compose up -d
```

To actually allow the bot to buy coins automatically, you need to reserve some autobuy funds.  You can do that by turning on the admin UI by enabling the `general.admin-ui` setting, the web UI shows you the Admin interface which allows you to change the autobuy funds amount.  It is not recommended to enable this if your web UI isn't secure using password authentication and HTTPS in case it's exposed to the Internet.

This example assigns 0.2 BTC to the "autobuy_funds". The higher the amount, the more coins can be bought and
the more arbitrage-opportunities can be taken. Be careful to keep at least 0.1 - 0.2 BTC at the exchange to give
the bot enough room to trade.

## Updating the bot
When you update the bot, you need to rebuild the docker containers in case they require rebuilding before rerunning the bot, you can do that with `docker-compose build`.

## Backing up the bot's data
The bot's mysql server saves its data in `docker/db-data`.  You are encouraged to back up the contents of this directory occasionally.  If you delete the contents of this directory, the next time you run the bot the bot will reinitialize its database from scratch (and will import an initial seed data from `data.sql` if that file exists.)

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

## Supporting Future Development
If you are running this bot and are making profits from it, please consider contributing towards future development of the project, either by contributing code or donating cryptocurrencies.  PR/BTC/ETH contributions are welcome:

* BTC address: `3PUsQxePwa5ck93DaSBy5i9YmGU9kKa9YG`
* ETH address: `0x2e732524459601546a93ee0307e1533bE69762d9`

Funding this project would allow me to spend time on things like adding support for new exchanges.

## Release History
* 2.0
    * Improved the web-based UI, charts, tooltips to help new users, legends
    * Added a P&L section in the UI to display detailed information about the incurred profits and losses as a result of trades, including daily and per-coin charts with filtering options.
    * Added precise accounting of unrealized profits/losses, taking all trade and transfer fees into account as reported by the exchanges.
    * Added accounting of realized (withdrawn) profits.
    * Added an Alerts section in the UI to display the stuck withdrawal and daily withdrawal limit alerts in the UI.
    * Add a "Hide zero balances" checkbox to the Wallets section
    * Added an admin UI for controlling the bot's settings from the web UI.
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
