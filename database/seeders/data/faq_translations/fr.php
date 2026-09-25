<?php

/*
 * French FAQ copy. Keyed by "English topic||English question" so the rows
 * attach correctly whatever the ids are in a given database.
 *
 * Brand names (RshopRefill, Rcoin, WhatsApp, Orange Money) and product names
 * stay as they are: they are names, not words.
 */

return [
    'topics' => [
        'Orders & Delivery' => 'Commandes et livraison',
        'Payments & Wallet' => 'Paiements et portefeuille',
        'Gift Cards & eSIMs' => 'Cartes cadeaux et eSIM',
        'Account & Verification' => 'Compte et vérification',
        'Transaction PIN & Security' => 'Code PIN et sécurité',
        'Refunds & Disputes' => 'Remboursements et litiges',
        'General' => 'Général',
        'Gift Cards' => 'Cartes cadeaux',
        'eSIMs' => 'eSIM',
        'Mobile Top-Ups' => 'Recharges mobiles',
        'Bill Payments' => 'Paiement de factures',
    ],

    'faqs' => [
        'Orders & Delivery||How fast is delivery?' => [
            'question' => 'Quels sont les délais de livraison ?',
            'answer' => 'La plupart des commandes numériques, comme les cartes cadeaux, les eSIM et les recharges, sont livrées immédiatement dans votre e-mail et votre tableau de bord dès la confirmation du paiement. Quelques articles peuvent demander quelques minutes de préparation.',
        ],
        'Orders & Delivery||Where do I find my codes?' => [
            'question' => 'Où puis-je trouver mes codes ?',
            'answer' => 'Ouvrez votre tableau de bord, allez dans Commandes et sélectionnez la commande. Les codes et les PIN livrés y figurent, et une copie est envoyée à votre e-mail de livraison.',
        ],
        'Orders & Delivery||My order says processing. What now?' => [
            'question' => 'Ma commande est en cours de traitement. Que faire ?',
            'answer' => 'En cours de traitement signifie que votre paiement a été accepté et que nous préparons votre article. Cela se termine généralement en quelques minutes. Si cela dure longtemps, contactez le support avec votre numéro de commande.',
        ],
        'Payments & Wallet||What payment methods can I use?' => [
            'question' => 'Quels moyens de paiement puis-je utiliser ?',
            'answer' => 'Vous pouvez payer par carte, virement bancaire, mobile money, cryptomonnaie ou avec le solde de votre portefeuille. Les moyens affichés dépendent de la devise choisie au moment du paiement.',
        ],
        'Payments & Wallet||How do I fund my wallet?' => [
            'question' => 'Comment alimenter mon portefeuille ?',
            'answer' => 'Allez dans Portefeuille depuis votre tableau de bord, choisissez Ajouter des fonds, sélectionnez un montant et un moyen de paiement, puis confirmez. Votre solde est mis à jour dès que le paiement est confirmé.',
        ],
        'Payments & Wallet||Can I hold more than one currency?' => [
            'question' => 'Puis-je détenir plusieurs devises ?',
            'answer' => 'Oui. Vous pouvez ouvrir un portefeuille dans chaque devise prise en charge depuis la page Portefeuille et alimenter celui dont vous avez besoin.',
        ],
        'Gift Cards & eSIMs||Are gift cards region locked?' => [
            'question' => 'Les cartes cadeaux sont-elles limitées à une région ?',
            'answer' => 'Certaines marques sont vendues par pays. La boutique indique les pays dans lesquels chaque marque est disponible, selon la région que vous avez sélectionnée.',
        ],
        'Gift Cards & eSIMs||How do I activate an eSIM?' => [
            'question' => 'Comment activer une eSIM ?',
            'answer' => 'Après l’achat, le QR code de votre eSIM et les informations d’activation apparaissent dans votre commande. Scannez le QR code dans les réglages de votre téléphone pour installer et activer le forfait.',
        ],
        'Account & Verification||Why do I need to verify my identity?' => [
            'question' => 'Pourquoi dois-je vérifier mon identité ?',
            'answer' => 'La vérification contribue à protéger votre compte et vos fonds, et peut débloquer des plafonds plus élevés. Envoyez vos documents depuis la page Vérifier l’identité de votre tableau de bord.',
        ],
        'Account & Verification||How long does verification take?' => [
            'question' => 'Combien de temps prend la vérification ?',
            'answer' => 'La plupart des dossiers sont examinés rapidement. Vous serez averti dès que votre vérification est approuvée ou si un élément supplémentaire est nécessaire.',
        ],
        'Transaction PIN & Security||What is a transaction PIN?' => [
            'question' => 'Qu’est-ce qu’un code PIN de transaction ?',
            'answer' => 'C’est un code à 4 chiffres qui autorise les paiements depuis le solde de votre portefeuille. Vous pouvez le définir ou le modifier dans Sécurité, depuis votre tableau de bord.',
        ],
        'Transaction PIN & Security||I forgot my transaction PIN.' => [
            'question' => 'J’ai oublié mon code PIN de transaction.',
            'answer' => 'Vous pouvez le modifier depuis la page Sécurité. Pour votre sécurité, le PIN se bloque après plusieurs tentatives erronées et se débloque automatiquement après une courte pause.',
        ],
        'Transaction PIN & Security||How do I keep my account safe?' => [
            'question' => 'Comment garder mon compte en sécurité ?',
            'answer' => 'Utilisez un mot de passe solide, définissez un code PIN de transaction et ne partagez jamais vos codes ou vos PIN. Nous ne vous demanderons jamais votre mot de passe ni votre PIN.',
        ],
        'Refunds & Disputes||Can I get a refund?' => [
            'question' => 'Puis-je obtenir un remboursement ?',
            'answer' => 'Les articles numériques livrés et déjà révélés ne peuvent généralement pas être remboursés. Si un article n’a pas été livré ou est défectueux, contactez le support et nous trouverons une solution.',
        ],
        'Refunds & Disputes||An order failed but I was charged.' => [
            'question' => 'Une commande a échoué mais j’ai été débité.',
            'answer' => 'Les paiements échoués sont annulés automatiquement et les fonds réservés dans le portefeuille sont libérés. Si un débit reste visible après un certain temps, contactez le support avec votre numéro de commande.',
        ],
        'General||What is RshopRefill?' => [
            'question' => 'Qu’est-ce que RshopRefill ?',
            'answer' => 'RshopRefill est une plateforme de produits numériques conçue et exploitée par Roddy Technologies Ltd via Roddy Labs. Elle réunit en un seul endroit des eSIM internationales, des cartes cadeaux, du crédit mobile, des forfaits data, des crédits de jeu, le paiement de factures et bien plus.',
        ],
        'General||Who can use RshopRefill?' => [
            'question' => 'Qui peut utiliser RshopRefill ?',
            'answer' => 'RshopRefill s’adresse aux voyageurs, aux joueurs, aux travailleurs à distance, aux étudiants, aux entreprises et à toute personne ayant besoin d’un accès rapide à des produits et services numériques fiables.',
        ],
        'General||What products and services does RshopRefill offer?' => [
            'question' => 'Quels produits et services propose RshopRefill ?',
            'answer' => 'Vous pouvez acheter des eSIM couvrant plus de 190 pays, des cartes cadeaux de milliers de marques internationales, du crédit mobile, des forfaits data, des crédits de jeu, payer les factures prises en charge et accéder à d’autres services numériques.',
        ],
        'General||How can RshopRefill help me while travelling?' => [
            'question' => 'En quoi RshopRefill m’aide-t-il en voyage ?',
            'answer' => 'Vous pouvez acheter et installer une eSIM avant votre départ. Vous restez ainsi connecté à destination sans changer de carte SIM physique ni payer des frais d’itinérance élevés.',
        ],
        'General||How quickly will I receive my purchase?' => [
            'question' => 'Sous quel délai vais-je recevoir mon achat ?',
            'answer' => 'La plupart des produits pris en charge sont livrés immédiatement ou en quelques minutes après un paiement réussi. Vous pouvez suivre votre commande et consulter ses informations de livraison depuis votre compte.',
        ],
        'General||Which payment methods does RshopRefill accept?' => [
            'question' => 'Quels moyens de paiement RshopRefill accepte-t-il ?',
            'answer' => 'Les moyens de paiement disponibles peuvent inclure le mobile money, le virement bancaire, les cartes de débit ou de crédit, les cryptomonnaies et votre portefeuille RshopRefill. La disponibilité dépend de votre pays et de la devise sélectionnée.',
        ],
        'General||Is it safe to make payments on RshopRefill?' => [
            'question' => 'Les paiements sur RshopRefill sont-ils sûrs ?',
            'answer' => 'Oui. RshopRefill s’appuie sur des prestataires de paiement sécurisés et sur des mesures de protection des comptes pour sécuriser vos transactions. Les paiements sont traités par des partenaires de confiance.',
        ],
        'General||What happens if I pay but do not receive my product?' => [
            'question' => 'Que se passe-t-il si je paie sans recevoir mon produit ?',
            'answer' => 'Si votre paiement est confirmé mais que le produit ne peut pas être livré, notre système déclenche un remboursement ou notre équipe de support examine et régularise la transaction. Les produits numériques correctement livrés sont en principe définitifs.',
        ],
        'General||Can I earn rewards when shopping on RshopRefill?' => [
            'question' => 'Puis-je gagner des récompenses en achetant sur RshopRefill ?',
            'answer' => 'Oui. Les achats éligibles peuvent rapporter des pièces de récompense RshopRefill. Vous pouvez également obtenir des récompenses supplémentaires via les parrainages, les promotions et d’autres activités éligibles.',
        ],
        'General||How can I get help with an order?' => [
            'question' => 'Comment obtenir de l’aide concernant une commande ?',
            'answer' => 'Connectez-vous à votre compte, ouvrez la commande concernée et contactez le support avec votre numéro de commande. Notre équipe peut vous aider pour les problèmes de paiement, de livraison, d’installation d’eSIM et les questions liées au compte.',
        ],
        'Gift Cards||What is a digital gift card?' => [
            'question' => 'Qu’est-ce qu’une carte cadeau numérique ?',
            'answer' => 'Une carte cadeau numérique est un code prépayé permettant d’acheter des produits, des jeux, des abonnements ou des services auprès d’une marque précise. Votre code est livré par voie numérique une fois votre commande finalisée.',
        ],
        'Gift Cards||How will I receive my gift card?' => [
            'question' => 'Comment vais-je recevoir ma carte cadeau ?',
            'answer' => 'Votre code de carte cadeau et les instructions d’utilisation apparaissent dans votre compte RShopRefill après le paiement et le traitement de la commande. Les produits pris en charge sont généralement livrés en quelques minutes.',
        ],
        'Gift Cards||Are gift cards restricted by country or region?' => [
            'question' => 'Les cartes cadeaux sont-elles limitées à un pays ou une région ?',
            'answer' => 'Oui. De nombreuses cartes cadeaux sont limitées à une région et ne fonctionnent qu’avec un compte enregistré dans le pays sélectionné. Vérifiez toujours le pays, la devise et la région du compte avant d’acheter.',
        ],
        'Gift Cards||Can I change the country or value after purchasing?' => [
            'question' => 'Puis-je changer le pays ou le montant après l’achat ?',
            'answer' => 'Non. La marque, le pays et le montant d’une carte cadeau ne peuvent normalement plus être modifiés une fois le code livré. Vérifiez attentivement votre sélection avant de finaliser le paiement.',
        ],
        'Gift Cards||How do I redeem my gift card?' => [
            'question' => 'Comment utiliser ma carte cadeau ?',
            'answer' => 'Ouvrez votre commande, copiez le code livré et suivez les instructions fournies. Vous devez l’utiliser sur le site officiel, l’application ou la boutique de la marque sélectionnée.',
        ],
        'Gift Cards||What payment methods can I use?' => [
            'question' => 'Quels moyens de paiement puis-je utiliser ?',
            'answer' => 'RShopRefill prend en charge les moyens de paiement disponibles tels que le mobile money, les virements bancaires, les cartes, les cryptomonnaies et les soldes de portefeuille éligibles. Les options varient selon le pays et la devise.',
        ],
        'Gift Cards||What should I do if my gift card has not arrived?' => [
            'question' => 'Que faire si ma carte cadeau n’est pas arrivée ?',
            'answer' => 'Vérifiez le statut de votre commande depuis la page Commandes. Si le paiement a réussi mais que le code n’a pas été livré, contactez le support RShopRefill avec votre référence de commande. Les commandes éligibles ayant échoué peuvent être remboursées automatiquement.',
        ],
        'Gift Cards||Can delivered gift cards be refunded?' => [
            'question' => 'Une carte cadeau livrée peut-elle être remboursée ?',
            'answer' => 'Les cartes cadeaux sont en principe définitives dès qu’un code valide a été livré. Un remboursement peut être envisagé si une commande échoue ou si aucun code utilisable n’est livré. Les codes déjà consultés, utilisés ou activés ne peuvent pas être repris.',
        ],
        'Gift Cards||What if my gift card code does not work?' => [
            'question' => 'Que faire si le code de ma carte cadeau ne fonctionne pas ?',
            'answer' => 'Vérifiez que la carte correspond bien au pays, à la devise et à la plateforme de votre compte. Saisissez le code exactement tel qu’il a été livré. Si cela échoue encore, contactez le support avec votre référence de commande et une capture d’écran de l’erreur. Ne partagez jamais le code complet publiquement.',
        ],
        'Gift Cards||Can I buy a gift card for someone else?' => [
            'question' => 'Puis-je acheter une carte cadeau pour quelqu’un d’autre ?',
            'answer' => 'Oui. Vous pouvez acheter une carte cadeau et transmettre le code livré, en toute sécurité, à une personne de confiance. Assurez-vous que son compte accepte le pays et la devise indiqués pour la carte.',
        ],
        'Gift Cards||Can I earn Rcoin when buying gift cards?' => [
            'question' => 'Puis-je gagner des Rcoin en achetant des cartes cadeaux ?',
            'answer' => 'Les achats de cartes cadeaux éligibles peuvent rapporter des Rcoin une fois la commande finalisée. Le montant et les conditions d’éligibilité sont affichés pendant l’achat ou dans la section Récompenses Rcoin.',
        ],
        'Gift Cards||How can I avoid gift card scams?' => [
            'question' => 'Comment éviter les arnaques aux cartes cadeaux ?',
            'answer' => 'Ne partagez un code de carte cadeau qu’avec son destinataire prévu. RShopRefill ne vous demandera jamais d’envoyer votre code complet par les réseaux sociaux, par messagerie ou lors d’un appel non sollicité. Traitez chaque code livré comme de l’argent liquide.',
        ],
        'eSIMs||What is an eSIM?' => [
            'question' => 'Qu’est-ce qu’une eSIM ?',
            'answer' => 'Une eSIM est une carte SIM numérique qui permet d’activer un forfait de données mobiles sans insérer de carte SIM physique. Après l’achat, vous pouvez l’installer sur un appareil compatible à l’aide du QR code ou des informations d’activation fournies.',
        ],
        'eSIMs||What is the difference between country, regional and global eSIMs?' => [
            'question' => 'Quelle est la différence entre une eSIM pays, régionale et mondiale ?',
            'answer' => 'Une eSIM pays couvre une seule destination. Une eSIM régionale fonctionne dans plusieurs pays d’une même région, comme l’Afrique, l’Europe ou l’Asie. Une eSIM mondiale couvre de nombreuses destinations avec un seul forfait. Vérifiez toujours la couverture indiquée avant d’acheter.',
        ],
        'eSIMs||How will I receive my eSIM?' => [
            'question' => 'Comment vais-je recevoir mon eSIM ?',
            'answer' => 'Les informations et les instructions d’installation de votre eSIM apparaissent dans votre compte RShopRefill après le paiement et le traitement de la commande. Les eSIM prises en charge sont généralement livrées en quelques minutes.',
        ],
        'eSIMs||Is my device compatible with eSIMs?' => [
            'question' => 'Mon appareil est-il compatible avec les eSIM ?',
            'answer' => 'La compatibilité dépend du modèle de votre appareil, de son pays de fabrication et de votre opérateur. Votre appareil doit prendre en charge la technologie eSIM et être désimlocké. Consultez notre liste d’appareils compatibles et les réglages de votre téléphone avant d’acheter.',
        ],
        'eSIMs||When should I install my eSIM?' => [
            'question' => 'Quand dois-je installer mon eSIM ?',
            'answer' => 'Nous conseillons d’installer votre eSIM avant de partir, tant que vous disposez d’une connexion internet stable. Certains forfaits démarrent toutefois dès l’installation, d’autres seulement à la connexion à un réseau pris en charge. Consultez toujours la politique d’activation indiquée sur la page de l’eSIM.',
        ],
        'eSIMs||How do I activate my eSIM?' => [
            'question' => 'Comment activer mon eSIM ?',
            'answer' => 'Ouvrez votre commande et suivez les instructions d’installation fournies. Vous devrez peut-être scanner un QR code ou saisir les informations d’activation manuellement. À votre arrivée, sélectionnez l’eSIM pour les données mobiles et activez l’itinérance des données pour cette ligne.',
        ],
        'eSIMs||Do I need to enable data roaming?' => [
            'question' => 'Dois-je activer l’itinérance des données ?',
            'answer' => 'Oui. L’itinérance des données doit généralement être activée pour que votre eSIM se connecte aux réseaux partenaires pris en charge. Cela n’entraîne pas de frais d’itinérance sur votre SIM principale tant que les données mobiles sont attribuées à l’eSIM.',
        ],
        'eSIMs||Can I use calls and SMS with my eSIM?' => [
            'question' => 'Puis-je passer des appels et envoyer des SMS avec mon eSIM ?',
            'answer' => 'La plupart des eSIM fournissent uniquement des données mobiles. Vous pouvez néanmoins appeler et envoyer des messages via des applications internet comme WhatsApp, Telegram, FaceTime et Messenger. Si un forfait inclut les appels, les SMS ou un numéro de téléphone, cela est clairement indiqué avant l’achat.',
        ],
        'eSIMs||Can I continue using my physical SIM?' => [
            'question' => 'Puis-je continuer à utiliser ma SIM physique ?',
            'answer' => 'Oui. Les appareils double SIM compatibles peuvent garder votre SIM physique active tout en utilisant l’eSIM pour les données mobiles. Pour éviter des frais d’itinérance imprévus, vérifiez que le basculement des données mobiles est désactivé et que l’eSIM est bien sélectionnée pour les données.',
        ],
        'eSIMs||Can I install the same eSIM on another device?' => [
            'question' => 'Puis-je installer la même eSIM sur un autre appareil ?',
            'answer' => 'En général, non. La plupart des eSIM ne peuvent être installées qu’une seule fois et ne sont pas transférables après l’installation. Ne supprimez pas l’eSIM de votre appareil tant que le forfait n’a pas expiré ou que vous ne l’avez pas terminé.',
        ],
        'eSIMs||How long does an eSIM plan remain valid?' => [
            'question' => 'Combien de temps un forfait eSIM reste-t-il valable ?',
            'answer' => 'La validité varie selon le forfait et peut commencer à l’achat, à l’installation ou à la première connexion à un réseau pris en charge. La durée et la politique d’activation sont affichées sur chaque forfait eSIM avant le paiement.',
        ],
        'eSIMs||Can I top up my eSIM?' => [
            'question' => 'Puis-je recharger mon eSIM ?',
            'answer' => 'Certains forfaits eSIM acceptent les recharges, d’autres exigent l’achat d’un nouveau forfait. Les options de recharge disponibles sont indiquées dans votre compte ou sur la page de l’eSIM concernée.',
        ],
        'eSIMs||What affects eSIM speed and coverage?' => [
            'question' => 'Qu’est-ce qui influe sur le débit et la couverture d’une eSIM ?',
            'answer' => 'Le débit et la couverture dépendent de votre destination, de votre position, de votre appareil, du forfait choisi, des réseaux locaux disponibles et de l’état du réseau. Votre eSIM peut se connecter à différents réseaux partenaires au fil de vos déplacements.',
        ],
        'eSIMs||What should I do if my eSIM is not working?' => [
            'question' => 'Que faire si mon eSIM ne fonctionne pas ?',
            'answer' => 'Vérifiez que l’eSIM est installée et activée, que l’itinérance des données est activée, que l’eSIM est sélectionnée pour les données mobiles et que vous êtes dans une destination prise en charge. Redémarrez votre appareil et suivez les instructions d’APN ou de sélection de réseau fournies. Si le problème persiste, contactez le support RShopRefill sans supprimer l’eSIM.',
        ],
        'eSIMs||Can I cancel or refund an eSIM purchase?' => [
            'question' => 'Puis-je annuler ou me faire rembourser un achat d’eSIM ?',
            'answer' => 'Si vous avez acheté la mauvaise eSIM, contactez immédiatement le support RShopRefill. Le droit au remboursement ou au remplacement dépend du fait que l’eSIM ait été installée, activée ou utilisée, ainsi que des conditions applicables du fournisseur. Les forfaits utilisés, expirés ou activés peuvent ne pas être remboursables.',
        ],
        'eSIMs||What payment methods can I use?' => [
            'question' => 'Quels moyens de paiement puis-je utiliser ?',
            'answer' => 'RShopRefill prend en charge les moyens de paiement disponibles tels que le Mobile Money, Orange Money, les virements bancaires, les cartes, les cryptomonnaies et les soldes de portefeuille éligibles. Les options varient selon le pays et la devise.',
        ],
        'eSIMs||Can I earn Rcoin when purchasing an eSIM?' => [
            'question' => 'Puis-je gagner des Rcoin en achetant une eSIM ?',
            'answer' => 'Les achats d’eSIM éligibles peuvent rapporter des Rcoin une fois la commande finalisée. La récompense applicable est affichée pendant l’achat ou dans la section Récompenses Rcoin.',
        ],
        'Mobile Top-Ups||What is a mobile top-up?' => [
            'question' => 'Qu’est-ce qu’une recharge mobile ?',
            'answer' => 'Une recharge mobile ajoute du crédit, du temps d’appel ou un forfait data à un numéro mobile prépayé. Vous choisissez l’opérateur, saisissez le numéro et sélectionnez un montant, et le crédit est envoyé directement sur cette ligne.',
        ],
        'Mobile Top-Ups||How quickly will the top-up arrive?' => [
            'question' => 'En combien de temps la recharge arrive-t-elle ?',
            'answer' => 'La plupart des recharges arrivent sur le numéro quelques minutes après la confirmation de votre paiement. La livraison dépend du traitement par l’opérateur mobile, les périodes de forte affluence peuvent donc ajouter un court délai.',
        ],
        'Mobile Top-Ups||Which networks and countries can I top up?' => [
            'question' => 'Quels opérateurs et quels pays puis-je recharger ?',
            'answer' => 'La boutique affiche tous les opérateurs actuellement disponibles pour le pays que vous avez sélectionné. Utilisez le filtre par pays pour voir ce qui est rechargeable et où, car la couverture varie selon la région et évolue à mesure que des opérateurs sont ajoutés.',
        ],
        'Mobile Top-Ups||What number should I enter?' => [
            'question' => 'Quel numéro dois-je saisir ?',
            'answer' => 'Saisissez le numéro mobile complet tel qu’il est composé localement, avec l’indicatif du pays lorsqu’il est demandé. Le numéro doit être une ligne prépayée active chez l’opérateur que vous avez choisi.',
        ],
        'Mobile Top-Ups||Can I top up a number in another country?' => [
            'question' => 'Puis-je recharger un numéro dans un autre pays ?',
            'answer' => 'Oui. Choisissez d’abord le pays de destination, puis l’opérateur qui dessert ce numéro. Le crédit arrive dans la devise locale de ce pays, quelle que soit la devise avec laquelle vous payez.',
        ],
        'Mobile Top-Ups||Can I change the number or amount after paying?' => [
            'question' => 'Puis-je modifier le numéro ou le montant après le paiement ?',
            'answer' => 'Non. Une fois la recharge envoyée sur un numéro, elle ne peut être ni annulée, ni redirigée, ni modifiée. Vérifiez le numéro chiffre par chiffre avant de confirmer le paiement.',
        ],
        'Mobile Top-Ups||What happens if I enter the wrong number?' => [
            'question' => 'Que se passe-t-il si je saisis un mauvais numéro ?',
            'answer' => 'Le crédit est versé au titulaire de ce numéro et ne peut pas être récupéré. RShopRefill ne peut pas récupérer une recharge envoyée à un numéro valide qui n’était pas le vôtre. Confirmez toujours le numéro avant de payer.',
        ],
        'Mobile Top-Ups||What payment methods can I use?' => [
            'question' => 'Quels moyens de paiement puis-je utiliser ?',
            'answer' => 'RShopRefill prend en charge les moyens de paiement disponibles tels que le mobile money, les virements bancaires, les cartes, les cryptomonnaies et les soldes de portefeuille éligibles. Les options varient selon le pays et la devise.',
        ],
        'Mobile Top-Ups||What should I do if my top-up has not arrived?' => [
            'question' => 'Que faire si ma recharge n’est pas arrivée ?',
            'answer' => 'Vérifiez le statut de votre commande depuis la page Commandes et confirmez le numéro saisi. Si le paiement a réussi mais que l’opérateur n’a pas livré, contactez le support RShopRefill avec votre numéro de commande. Les commandes échouées éligibles peuvent être remboursées automatiquement.',
        ],
        'Mobile Top-Ups||Can a completed top-up be refunded?' => [
            'question' => 'Une recharge effectuée peut-elle être remboursée ?',
            'answer' => 'Les recharges sont définitives une fois le numéro crédité par l’opérateur. Un remboursement peut être envisagé lorsqu’une commande échoue ou qu’aucun crédit n’est livré. Une recharge livrée au numéro que vous avez saisi ne peut pas être reprise.',
        ],
        'Mobile Top-Ups||Can I top up someone else\'s number?' => [
            'question' => 'Puis-je recharger le numéro de quelqu’un d’autre ?',
            'answer' => 'Oui. Vous pouvez envoyer une recharge vers tout numéro prépayé actif chez un opérateur pris en charge, y compris dans d’autres pays. Le numéro suffit.',
        ],
        'Mobile Top-Ups||Can I earn Rcoin on mobile top-ups?' => [
            'question' => 'Puis-je gagner des Rcoin sur les recharges mobiles ?',
            'answer' => 'Les recharges éligibles peuvent rapporter des Rcoin une fois la commande finalisée. Le montant et l’éligibilité sont affichés pendant l’achat ou dans la section Récompenses Rcoin.',
        ],
        'Bill Payments||What is a bill payment?' => [
            'question' => 'Qu’est-ce qu’un paiement de facture ?',
            'answer' => 'Un paiement de facture règle un compte de service prépayé, comme l’électricité ou l’eau, directement depuis RShopRefill. Vous choisissez le fournisseur, saisissez le numéro de compte ou de compteur et payez, et le montant est appliqué à ce compte.',
        ],
        'Bill Payments||Which bills can I pay?' => [
            'question' => 'Quelles factures puis-je payer ?',
            'answer' => 'La boutique affiche tous les fournisseurs actuellement disponibles pour le pays que vous avez sélectionné. Utilisez le filtre par pays pour voir ce qui est payable et où, car les fournisseurs disponibles varient selon la région et évoluent à mesure que de nouveaux sont ajoutés.',
        ],
        'Bill Payments||How quickly is a bill payment applied?' => [
            'question' => 'En combien de temps un paiement de facture est-il appliqué ?',
            'answer' => 'La plupart des paiements parviennent au fournisseur quelques minutes après la confirmation de votre paiement. Le fournisseur l’applique ensuite au compte, ce qui peut prendre plus de temps en période de forte affluence.',
        ],
        'Bill Payments||What details do I need to pay a bill?' => [
            'question' => 'Quelles informations me faut-il pour payer une facture ?',
            'answer' => 'Il vous faut le numéro de compte, de compteur ou de client exactement tel qu’il figure sur votre facture ou votre compteur, ainsi que le pays du compte. Certains fournisseurs demandent aussi le nom du titulaire.',
        ],
        'Bill Payments||What happens if I enter the wrong account or meter number?' => [
            'question' => 'Que se passe-t-il si je saisis un mauvais numéro de compte ou de compteur ?',
            'answer' => 'Le paiement est appliqué au compte correspondant au numéro saisi et ne peut pas être annulé. RShopRefill ne peut pas récupérer un paiement effectué vers un compte valide qui n’était pas le vôtre, vérifiez donc le numéro avec attention avant de payer.',
        ],
        'Bill Payments||Will I receive a token or receipt?' => [
            'question' => 'Vais-je recevoir un jeton ou un reçu ?',
            'answer' => 'Oui. Les compteurs prépayés renvoient un jeton ou une référence une fois le paiement traité par le fournisseur. Il apparaît dans votre commande avec le reçu de paiement, et une copie est envoyée à votre e-mail de livraison.',
        ],
        'Bill Payments||Can I change the account or amount after paying?' => [
            'question' => 'Puis-je modifier le compte ou le montant après le paiement ?',
            'answer' => 'Non. Une fois le paiement envoyé à un fournisseur, il ne peut être ni annulé, ni redirigé, ni modifié. Vérifiez le numéro de compte et le montant avant de confirmer le paiement.',
        ],
        'Bill Payments||What payment methods can I use?' => [
            'question' => 'Quels moyens de paiement puis-je utiliser ?',
            'answer' => 'RShopRefill prend en charge les moyens de paiement disponibles tels que le mobile money, les virements bancaires, les cartes, les cryptomonnaies et les soldes de portefeuille éligibles. Les options varient selon le pays et la devise.',
        ],
        'Bill Payments||What should I do if my payment has not been applied?' => [
            'question' => 'Que faire si mon paiement n’a pas été appliqué ?',
            'answer' => 'Vérifiez le statut de votre commande depuis la page Commandes et confirmez le numéro de compte saisi. Si le paiement a réussi mais que le fournisseur ne l’a pas appliqué, contactez le support RShopRefill avec votre numéro de commande. Les commandes échouées éligibles peuvent être remboursées automatiquement.',
        ],
        'Bill Payments||Can a bill payment be refunded?' => [
            'question' => 'Un paiement de facture peut-il être remboursé ?',
            'answer' => 'Les paiements de factures sont définitifs une fois acceptés par le fournisseur. Un remboursement peut être envisagé lorsqu’une commande échoue ou que rien n’est livré. Un paiement appliqué au compte que vous avez saisi ne peut pas être repris.',
        ],
        'Bill Payments||Can I pay a bill for someone else?' => [
            'question' => 'Puis-je payer la facture de quelqu’un d’autre ?',
            'answer' => 'Oui. Vous pouvez payer toute facture prise en charge à partir de son numéro de compte ou de compteur, y compris dans d’autres pays. Les informations du compte suffisent.',
        ],
        'Bill Payments||Can I earn Rcoin on bill payments?' => [
            'question' => 'Puis-je gagner des Rcoin sur les paiements de factures ?',
            'answer' => 'Les paiements de factures éligibles peuvent rapporter des Rcoin une fois la commande finalisée. Le montant et l’éligibilité sont affichés pendant l’achat ou dans la section Récompenses Rcoin.',
        ],
    ],
];
