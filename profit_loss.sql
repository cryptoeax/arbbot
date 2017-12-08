-- --------------------------------------------------------

--
-- Table structure for table `profit_loss`
--
CREATE TABLE `profit_loss` (
  `ID` int(11) NOT NULL AUTO_INCREMENT,
  `created` int(11) NOT NULL,
  `ID_exchange_source` int(11) NOT NULL,
  `ID_exchange_target` int(11) NOT NULL,
  `coin` char(5) NOT NULL,
  `currency` char(5) NOT NULL,
  `raw_trade_IDs_buy` varchar(4096) NOT NULL,
  `trade_IDs_buy` varchar(4096) NOT NULL,
  `raw_trade_IDs_sell` varchar(4096) NOT NULL,
  `trade_IDs_sell` varchar(4096) NOT NULL,
  `rate_buy` varchar(18) NOT NULL,
  `rate_sell` varchar(18) NOT NULL,
  `tradeable_bought` varchar(18) NOT NULL,
  `tradeable_sold` varchar(18) NOT NULL,
  `currency_bought` varchar(18) NOT NULL,
  `currency_sold` varchar(18) NOT NULL,
  `currency_revenue` varchar(18) NOT NULL,
  `currency_pl` varchar(18) NOT NULL,
  `tradeable_tx_fee` varchar(18) NOT NULL,
  `currency_tx_fee` varchar(18) NOT NULL,
  `buy_fee` varchar(18) NOT NULL,
  `sell_fee` varchar(18) NOT NULL,
  PRIMARY KEY (`ID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 AUTO_INCREMENT=1 ;

