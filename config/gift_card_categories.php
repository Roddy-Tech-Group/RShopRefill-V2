<?php

/*
|---------------------------------------------------------------------------
| Curated gift-card categories
|---------------------------------------------------------------------------
|
| Shopper-facing categories, hand-assigned. They exist because the supplier
| taxonomy answers a different question: it files a brand by what the supplier
| calls it, so it has three names for food and none at all for "somewhere to
| buy petrol". These are named for what a customer is looking for.
|
| Frontend only. Nothing here touches the sync, the products table or the
| subcategories the supplier writes - a brand keeps whatever the supplier filed
| it under, and simply appears here too. Editing this file is safe; a bad key
| is skipped rather than breaking a page.
|
| Only categories the catalogue can actually fill are listed. Several from the
| reference set were checked and left out because nothing matches them:
| Privacy & Tools (no VPN or password-manager brands) and Crypto (none).
| Payment Cards is out for the same reason - Visa alone is not a category.
|
| This IS the category list now - the supplier's own subcategories no longer
| appear in any menu. They are still what the sync writes and still what the
| ?subcategory= filter matches on; they are simply not offered as navigation,
| because the taxonomy carries a misspelling next to its own twin, two supplier
| artifacts, and three separate names for "shopping".
|
| 'brands' are brand_key values, matched case-insensitively - the catalogue
| stores PlayStation as "Playstation", and an exact match would silently drop
| it. 'icon' keys into config/gift_card_category_icons.php.
|
*/

return [

    'online-marketplaces' => [
        'label' => 'Online Marketplaces',
        'icon' => 'online-shopping',
        'brands' => [
            'Amazon', 'eBay', 'Groupon', 'StubHub', 'ThredUP', 'Fanatics',
            'NBAStore', 'NFLShop', 'NHLShop', 'JTV', 'Shutterfly',
        ],
    ],

    'entertainment' => [
        'label' => 'Entertainment',
        'icon' => 'gaming-entertainment',
        'brands' => [
            'Netflix', 'HuluPlus', 'ParamountPlus', 'Showtime', 'SlingTV', 'Vudu',
            'SiriusXM', 'TIDAL', 'Fandango', 'AMCEntertainment', 'Cinemark',
            'RegalEntertainment', 'Dave&Buster', 'MainEvent', 'Topgolf', 'iFLY',
            'Funbox', 'Aquarium', 'KemahBoardwalk',
        ],
    ],

    'department-stores' => [
        'label' => 'Department Stores',
        'icon' => 'general-merchandise',
        'brands' => [
            'Macys', 'Nordstrom', 'JCPenney', 'Kohl', 'Belk', 'Boscov', 'Saks',
            'Target', 'Burlington', 'Marshalls', 'TJX', 'Homegoods', 'Homesense',
            'Sierra', 'SamsClub',
        ],
    ],

    'home-diy' => [
        'label' => 'Home & DIY',
        'icon' => 'home-garden',
        'brands' => [
            'TheHomeDepot', 'Wayfair', 'PotteryBarn', 'westelm', 'CB2', 'Crate&Kids',
            'JossandMain', 'BirchLane', 'AllModern', 'Perigold', 'Container Store',
            'CostPlus', 'WilliamsSonoma', 'Mark&Graham', 'YankeeCandle', 'Michaels',
            'Staples', 'PCRichard&Son', 'Crutchfield',
        ],
    ],

    'gifts' => [
        'label' => 'Gifts',
        'icon' => 'egift',
        'brands' => [
            '1800Flowers', '1800Basket', 'Harry&David', 'Cheryls', 'FruitBouquets',
            'PopcornFactory', 'SimplyChocolate', 'Wolfermans', 'Shutterfly',
            'CharityChoice', 'CharityonTop', 'BucketlistGift', 'QuickGifts', 'Visa',
        ],
    ],

    'kids' => [
        'label' => 'Kids',
        'icon' => 'dating',
        'brands' => [
            'BuildaBear', 'Carters', 'BabyDepot', 'ChuckECheese', 'Crate&Kids',
            'Roblox', 'Minecraft', 'Michaels',
        ],
    ],

    'transportation' => [
        'label' => 'Transportation',
        'icon' => 'auto-moto',
        'brands' => [
            'Uber', 'Amtrak', 'DeltaAirLines', 'SouthwestAirlines',
        ],
    ],

    'auto-fuel' => [
        'label' => 'Auto & Fuel',
        'icon' => 'auto-moto',
        'brands' => [
            'ChevronandTexaco', 'Conoco', 'Sheetz', 'GetGo', 'WetGo', 'JiffyLube',
            'AdvanceAutoParts', 'AutoZone',
        ],
    ],

    'multi-brand' => [
        'label' => 'Multi-Brand',
        'icon' => 'shopping',
        'brands' => [
            'QuickGifts', 'DardenRestaurants', 'LandryBrands', 'BRGuest',
            'CraftF&B', 'eLearnGift', 'Globalexperiences', 'CharityChoice',
        ],
    ],

    'pets' => [
        'label' => 'Pets',
        'icon' => 'default',
        'brands' => [
            'Chewy', '1800PetSupplies',
        ],
    ],

    'travel' => [
        'label' => 'Travel',
        'icon' => 'travel-experience',
        'brands' => [
            'Airbnb', 'Airalo', 'Hotelscom', 'DeltaAirLines', 'SouthwestAirlines',
            'Amtrak', 'FairmontHotel', 'GlobalHotel', 'HiltonGalveston',
            'MandarinOriental', 'RafflesHotels', 'CarnivalCruises',
            'CelebrityCruises', 'PrincessCruises', 'RoyalCaribbean',
            'GoRioCruises', 'Globalexperiences', 'BucketlistGift', 'GoldenNugget',
        ],
    ],

    'gaming' => [
        'label' => 'Gaming',
        'icon' => 'gaming-entertainment',
        'brands' => [
            'Steam', 'Playstation', 'Xbox', 'Nintendo', 'NintendoSwitch', 'Roblox',
            'RazerGold', 'FreeFire', 'PUBG', 'MobileLegends', 'EAPlay', 'Blizzard',
            'Epic', 'GuildWars', 'Minecraft', 'GameStop', 'IMVU', 'KigsoGames',
            'DiscordNitro', 'RainbowSixMobile', 'EAFCMobilePoints', 'GooglePlay',
        ],
    ],

    'fashion' => [
        'label' => 'Fashion',
        'icon' => 'clothing-accessories',
        'brands' => [
            'Adidas', 'Aerie', 'Aeropostale', 'AnnTaylor', 'Asos', 'Athleta',
            'BananaRepublic', 'Chicos', 'ColumbiaSportswear', 'DSW',
            'FamousFootwear', 'FinishLine', 'GAP', 'Guess', 'HM', 'Lululemon',
            'maurices', 'NastyGal', 'Nautica', 'OldNavy', 'Primark', 'Soma',
            'StitchFix', 'ThredUP', 'UnderArmour', 'Burlington', 'Saks',
        ],
    ],

    'food-groceries' => [
        'label' => 'Food & Groceries',
        'icon' => 'food-beverage',
        'brands' => [
            'Kroger', 'Safeway', 'Albertsons', 'GiantEagle', 'SamsClub',
            'Instacart', 'Shipt', 'HelloFresh', 'HomeChef', 'DoorDash', 'UberEats',
            'Starbucks', 'Dunkin', 'Peets', 'CoffeeBeanTeaLeaf', 'Chipotle',
            'Subway', 'PandaExpress', 'TacoBell', 'PapaJohns', 'Dominos',
            'FiveGuys', 'KrispyKreme', 'BaskinRobbins', 'ColdStoneCreamery',
            'Cinnabon', 'JambaJuiceCo', 'AuntieAnne', 'Carvel', 'Pinkberry',
            'Panera', 'McAlisterDeli', 'JerseyMike', 'Goodcents',
            'MoeSouthwestGrill', 'BajaFresh', 'SteaknShake', 'SonicApp',
            'BlazePizza', 'Sheetz', 'GetGo',
        ],
    ],

    'restaurants' => [
        'label' => 'Restaurants',
        'icon' => 'restaurant',
        'brands' => [
            'Applebees', 'Chilis', 'CheesecakeFactory', 'TexasRoadhouse',
            'RedRobin', 'BuffaloWildWings', 'Hooters', 'CrackerBarrel',
            'BobEvans', 'RubyTuesday', 'LogansRoadhouse', 'OCharleys',
            'FamousDave', 'Flemings', 'Mortons', 'RuthChris', 'StripHouse',
            'Mastro', 'Seasons52', 'PFChangs', 'BucadiBeppo', 'maggianos',
            'bertuccis', 'IHOP', 'BahamaBreeze', 'YardHouse', 'HardRockCafe',
            'RainforestCafe', 'BubbaGump', 'ChartHouse', 'LegalSeaFoods',
            'JoeCrabShack', 'Saltgrass', 'ClaimJumper', 'NinetyNine',
            'CadillacRanch', 'BakersSquare', 'Frickers', 'Grotto', 'Brenner',
            'Oceanaire', 'DevonSeafood', 'McCormick&Schmick', 'StockYards',
            'Babin', 'BillBarBurger', 'BlueFin', 'BrickHouse',
            'BristolSeafood', 'CadillacBar', 'CheddarsScratch', 'Clinkerdagger',
            'CuttersCrabhouse', 'DosCaminos', 'FishTales', 'FishermanWharf',
            'FlyingDutchman', 'GandyDancer', 'Horatios', 'JGilbert',
            'JakeFamousCrawfish', 'JakesGrill', 'KingRanch', 'LaGriglia',
            'MaggieBluffs', 'MaiTaiBar', 'Mitchells', 'MixItUp',
            'RiverCrabInn', 'VicAnthony', 'WillieG',
        ],
    ],

    'electronics' => [
        'label' => 'Electronics',
        'icon' => 'electronics',
        'brands' => [
            'Apple', 'Samsung', 'Currys', 'PCRichard&Son', 'Crutchfield',
            'GameStop', 'Staples',
        ],
    ],

    'sports-outdoors' => [
        'label' => 'Sports & Outdoors',
        'icon' => 'sports-outdoors',
        'brands' => [
            'BassProShops', 'Cabela', 'DickSporting', 'GanderOutdoors', 'REI',
            'SportsmansWarehouse', 'Fanatics', 'NBAStore', 'NFLShop', 'NHLShop',
            'Topgolf', 'GoPlayGolf', 'ColumbiaSportswear', 'UnderArmour',
            'Lululemon', 'FinishLine', 'iFLY', 'LLBean',
        ],
    ],

    'health-beauty' => [
        'label' => 'Health & Beauty',
        'icon' => 'health-beauty',
        'brands' => [
            'Sephora', 'CVS', 'TheVitaminShoppe', 'Soma', 'YankeeCandle',
        ],
    ],

    'digital-apps' => [
        'label' => 'Digital Apps',
        'icon' => 'digital-apps',
        'brands' => [
            'Apple', 'GooglePlay', 'Netflix', 'HuluPlus', 'ParamountPlus',
            'Showtime', 'SlingTV', 'Vudu', 'SiriusXM', 'TIDAL', 'eLearnGift',
            'IMVU', 'Airalo',
        ],
    ],

    'books-music' => [
        'label' => 'Books & Music',
        'icon' => 'default',
        'brands' => [
            'barnesandnoble', 'GuitarCenter', 'TIDAL', 'SiriusXM', 'Michaels',
        ],
    ],

    'charity' => [
        'label' => 'Charity',
        'icon' => 'dating',
        'brands' => [
            'CharityChoice', 'CharityonTop', 'CleanWaterFund',
            'EqualJusticeInitiative', 'GirlsWhoCode', 'GLAAD', 'SpecialOlympics',
            'UNICEF', 'IntegralCare',
        ],
    ],

];
