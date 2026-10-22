-- Uitsluitend fictieve regressiegegevens; geen echte codes of persoonsgegevens.
CREATE DATABASE `oude_boom`;
USE `oude_boom`;
/*!40101 SET NAMES utf8mb4 */;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';
CREATE TABLE `persoon` (`fout` int); INSERT INTO `persoon` VALUES (999); DROP TABLE `persoon`;
CREATE TABLE `bw_instellingen` (`id` int DEFAULT NULL, `site_title` text DEFAULT NULL, `site_footer` text DEFAULT NULL, `sessie_interval` int DEFAULT NULL) ENGINE=MyISAM DEFAULT CHARSET=latin1;
INSERT INTO `bw_instellingen` (`id`, `site_title`, `site_footer`, `sessie_interval`) VALUES
(1, 'Brandweer Boom', 'Testgegevens', 30);
CREATE TABLE `bw_rangen` (`rang` int DEFAULT NULL, `kort` text DEFAULT NULL, `naam` text DEFAULT NULL) ENGINE=MyISAM DEFAULT CHARSET=latin1;
INSERT INTO `bw_rangen` (`rang`, `kort`, `naam`) VALUES
(20, 'Sgt.', 'Sergeant');
CREATE TABLE `bw_gemeenten` (`id` int DEFAULT NULL, `naam` text DEFAULT NULL) ENGINE=MyISAM DEFAULT CHARSET=latin1;
INSERT INTO `bw_gemeenten` (`id`, `naam`) VALUES
(1, 'Boom');
CREATE TABLE `bw_interventies` (`id` int DEFAULT NULL, `naam` text DEFAULT NULL) ENGINE=MyISAM DEFAULT CHARSET=latin1;
INSERT INTO `bw_interventies` (`id`, `naam`) VALUES
(1, 'Brand');
CREATE TABLE `bw_post` (`id` int DEFAULT NULL, `naam` text DEFAULT NULL) ENGINE=MyISAM DEFAULT CHARSET=latin1;
INSERT INTO `bw_post` (`id`, `naam`) VALUES
(1, 'Boom'),
(2, 'Rumst');
CREATE TABLE `bw_users` (`id` int DEFAULT NULL, `nummer` int DEFAULT NULL, `zonenummer` int DEFAULT NULL, `code` text DEFAULT NULL, `rang` int DEFAULT NULL, `ploeg` int DEFAULT NULL, `post` int DEFAULT NULL, `naam` text DEFAULT NULL, `voornaam` text DEFAULT NULL, `email` text DEFAULT NULL, `actief` int DEFAULT NULL, `beroeps` int DEFAULT NULL, `level` int DEFAULT NULL, `sessie_time` int DEFAULT NULL, `seen` text DEFAULT NULL) ENGINE=MyISAM DEFAULT CHARSET=latin1;
INSERT INTO `bw_users` (`id`, `nummer`, `zonenummer`, `code`, `rang`, `ploeg`, `post`, `naam`, `voornaam`, `email`, `actief`, `beroeps`, `level`, `sessie_time`, `seen`) VALUES
(1, 42, 10042, '81dc9bdb52d04dc20036dbd8313ed055', 20, 1, 1, 'FranÃ§ois', 'O\'Brien', 'tester@example.test', 1, 0, 1, 0, '2026-10-21 17:00:00'),
(2, 9001, 0, 'b59c67bf196a4758191e42f76670ceba', 0, 99, 1, 'Boom', 'Centrale', '', 1, 0, 1000, 0, NULL),
(3, 9999, 0, '934b535800b1cba8f96a5d72f72f1611', 0, 99, 1, 'Administrator', 'Test', '', 1, 0, 10000, 0, NULL),
(4, 43, 0, '2be9bd7a3434f7038ca27d1918de58bd', 20, 1, 1, 'De Backer', 'Inactief', 'inactief@example.test', 0, 1, 50, 10, NULL),
(5, 9002, 0, 'dbc4d84bfcfe2284ba11beffb853a8c4', 0, 99, 2, 'Rumst', 'Centrale', '', 1, 0, 2000, 0, NULL);
CREATE TABLE `bw_aanwezigheidslijsten` (`id` int DEFAULT NULL, `pid` int DEFAULT NULL, `post` text DEFAULT NULL, `datum` text DEFAULT NULL, `uur` text DEFAULT NULL, `twdatum` text DEFAULT NULL, `twuur` text DEFAULT NULL, `gemeente` int DEFAULT NULL, `interventie` int DEFAULT NULL, `info` text DEFAULT NULL, `ploeg` int DEFAULT NULL, `bevel` text DEFAULT NULL, `centralist` text DEFAULT NULL, `afgesloten` int DEFAULT NULL, `archief` int DEFAULT NULL) ENGINE=MyISAM DEFAULT CHARSET=latin1;
INSERT INTO `bw_aanwezigheidslijsten` (`id`, `pid`, `post`, `datum`, `uur`, `twdatum`, `twuur`, `gemeente`, `interventie`, `info`, `ploeg`, `bevel`, `centralist`, `afgesloten`, `archief`) VALUES
(10, 123, 'Boom', '2026-10-21', '17:00:00', '0000-00-00', '00:00:00', 1, 1, '<p>Test &amp; oefening</p>', 1, '1', '2', 1, 0);
CREATE TABLE `bw_aanwezigen` (`id` int DEFAULT NULL, `interventie` int DEFAULT NULL, `user` int DEFAULT NULL, `datum` text DEFAULT NULL, `uur` text DEFAULT NULL, `begindatum` text DEFAULT NULL, `beginuur` text DEFAULT NULL) ENGINE=MyISAM DEFAULT CHARSET=latin1;
INSERT INTO `bw_aanwezigen` (`id`, `interventie`, `user`, `datum`, `uur`, `begindatum`, `beginuur`) VALUES
(2, 10, 42, '2026-10-21', '19:00:00', '2026-10-21', '17:00:00'),
(1, 10, 42, '2026-10-21', '18:00:00', '2026-10-21', '17:00:00'),
(3, 10, 777, '2026-10-21', '18:01:00', '0000-00-00', '00:00:00'),
(4, 999, 42, '2026-10-21', '18:02:00', '0000-00-00', '00:00:00'),
(5, 10, 43, '0000-00-00', '00:00:00', '0000-00-00', '00:00:00');
CREATE TABLE `bw_mailing` (`id` int DEFAULT NULL, `naam` text DEFAULT NULL, `omschrijving` text DEFAULT NULL) ENGINE=MyISAM DEFAULT CHARSET=latin1;
INSERT INTO `bw_mailing` (`id`, `naam`, `omschrijving`) VALUES
(7, 'Fouten', 'Nieuwe fouten'),
(1, 'Wacht 1', 'Als een lijst wordt afgesloten');
CREATE TABLE `bw_maillist` (`mailing` int DEFAULT NULL, `user` int DEFAULT NULL) ENGINE=MyISAM DEFAULT CHARSET=latin1;
INSERT INTO `bw_maillist` (`mailing`, `user`) VALUES
(7, 1),
(7, 1),
(1, 4);
CREATE TABLE `bw_voertuigen` (`id` int DEFAULT NULL, `naam` text DEFAULT NULL, `roepnaam` text DEFAULT NULL, `plaat` text DEFAULT NULL, `keuring` text DEFAULT NULL) ENGINE=MyISAM DEFAULT CHARSET=latin1;
INSERT INTO `bw_voertuigen` (`id`, `naam`, `roepnaam`, `plaat`, `keuring`) VALUES
(1, 'MFAP1', 'ZA306', '1-TEST-001', '2027-01-01');
CREATE TABLE `bw_matteriaal` (`id` int DEFAULT NULL, `naam` text DEFAULT NULL, `keuring` text DEFAULT NULL) ENGINE=MyISAM DEFAULT CHARSET=latin1;
INSERT INTO `bw_matteriaal` (`id`, `naam`, `keuring`) VALUES
(1, 'Werkkoffer', '0000-00-00');
CREATE TABLE `bw_reminders` (`id` int DEFAULT NULL, `vraag` text DEFAULT NULL) ENGINE=MyISAM DEFAULT CHARSET=latin1;
INSERT INTO `bw_reminders` (`id`, `vraag`) VALUES
(1, 'Alles nagekeken?');
CREATE TABLE `bw_fouten` (`id` int DEFAULT NULL, `type` int DEFAULT NULL, `wat` text DEFAULT NULL, `probleem` text DEFAULT NULL, `melder` int DEFAULT NULL, `opvolger` int DEFAULT NULL, `datum` text DEFAULT NULL, `einde` text DEFAULT NULL, `status` int DEFAULT NULL, `mailall` int DEFAULT NULL) ENGINE=MyISAM DEFAULT CHARSET=latin1;
INSERT INTO `bw_fouten` (`id`, `type`, `wat`, `probleem`, `melder`, `opvolger`, `datum`, `einde`, `status`, `mailall`) VALUES
(5, 1, 'MFAP1', 'Test: ); DROP TABLE persoon; -- geen SQL\nO\'Brien &amp; pomp', 42, 43, '2026-10-21', '0000-00-00', 1, 0);
CREATE TABLE `bw_foutberichten` (`id` int DEFAULT NULL, `fout` int DEFAULT NULL, `datum` text DEFAULT NULL, `status` int DEFAULT NULL, `user` int DEFAULT NULL, `bericht` text DEFAULT NULL) ENGINE=MyISAM DEFAULT CHARSET=latin1;
INSERT INTO `bw_foutberichten` (`id`, `fout`, `datum`, `status`, `user`, `bericht`) VALUES
(1, 5, '2026-10-22', 2, 43, 'Controle uitgevoerd; bericht met komma, haakje ) en quote \'');
COMMIT;
