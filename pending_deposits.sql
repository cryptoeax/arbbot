-- --------------------------------------------------------

--
-- Table structure for table `pending_deposits`
--

CREATE TABLE IF NOT EXISTS `pending_deposits` (
  `ID` int(11) NOT NULL AUTO_INCREMENT,
  `ID_withdrawal` int(11) NULL,
  `created` int(11) NOT NULL,
  `ID_exchange` int(11) NOT NULL,
  `coin` char(10) NOT NULL,
  `amount` varchar(18) NOT NULL,
  PRIMARY KEY (`ID`),
  FOREIGN KEY (`ID_withdrawal`) REFERENCES `withdrawal`(`ID`)
    ON DELETE SET NULL
    ON UPDATE CASCADE
) ENGINE=InnoDB  DEFAULT CHARSET=utf8 AUTO_INCREMENT=1 ;