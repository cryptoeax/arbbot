-- --------------------------------------------------------

--
-- Table structure for table `profits`
--

CREATE TABLE IF NOT EXISTS `profits` (
  `ID` int(11) NOT NULL AUTO_INCREMENT,
  `created` int(11) NOT NULL,
  `currency` char(5) NOT NULL,
  `amount` varchar(18) NOT NULL,
  `cash_restock_percent` varchar(18) NOT NULL,
  `cash_restock_amount` varchar(18) NOT NULL,
  `address` text NOT NULL,
  PRIMARY KEY (`ID`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8 AUTO_INCREMENT=1 ;

