-- --------------------------------------------------------

--
-- Table structure for table `balances`
--

CREATE TABLE IF NOT EXISTS `balances` (
  `ID` int(11) NOT NULL AUTO_INCREMENT,
  `created` int(11) NOT NULL,
  `coin` char(5) NOT NULL,
  `value` varchar(18) NOT NULL,
  `raw` varchar(18) NOT NULL,
  `ID_exchange` int(11) NOT NULL,
  PRIMARY KEY (`ID`),
  KEY `coin` (`coin`),
  KEY `ID_exchange` (`ID_exchange`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8 AUTO_INCREMENT=1 ;
