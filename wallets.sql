-- --------------------------------------------------------

--
-- Table structure for table `wallets`
--

CREATE TABLE IF NOT EXISTS `wallets` (
  `ID_exchange` int(11) NOT NULL,
  `coin` char(10) NOT NULL,
  `created` int(11) NOT NULL,
  `amount` varchar(18) NOT NULL,
  PRIMARY KEY (`ID_exchange`, `coin`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8 ;
