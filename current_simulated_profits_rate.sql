-- --------------------------------------------------------

--
-- View structure for view `current_snapshot`
--

CREATE OR REPLACE VIEW `current_snapshot` AS
SELECT * FROM snapshot WHERE created = (SELECT MAX(created) FROM snapshot);

-- --------------------------------------------------------

--
-- View structure for view `current_simulated_profit_rate_raw`
--

CREATE OR REPLACE VIEW `current_simulated_profit_rate_raw` AS
SELECT t.`created`, t.`coin`, t.`currency`, SUM(t.`amount`) * `rate` AS `price`,
       SUM(t.`profit`) AS `profit`, `profit` / (SUM(t.`amount`) * `rate`) AS `ratio`, `ID_exchange_source`, `ID_exchange_target`
FROM `track` AS t INNER JOIN `current_snapshot` AS s ON
     t.`coin` = s.`coin` AND t.`ID_exchange_target` = s.`ID_exchange`
WHERE UNIX_TIMESTAMP() - t.`created` < 24 * 60 * 60
GROUP BY t.`coin`, t.`currency`, `ID_exchange_source`, `ID_exchange_target`
ORDER BY `profit` / (SUM(t.`amount`) * `rate`) DESC;

-- --------------------------------------------------------

--
-- View structure for view `current_simulated_profit_rate`
--

CREATE OR REPLACE VIEW `current_simulated_profit_rate` AS
SELECT `created`, `coin`, `currency`, `price`, `profit`,
       FLOOR(`ratio` / (SELECT ratio FROM `current_simulated_profit_rate_raw` ORDER BY `ratio` ASC LIMIT 1)) AS `ratio`,
       `ID_exchange_source`, `ID_exchange_target`
FROM `current_simulated_profit_rate_raw`
ORDER BY `ratio` DESC;
