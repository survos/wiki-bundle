<?php

declare(strict_types=1);

namespace Survos\WikiBundle\Entity;

enum WikiProperty: string
{
    case IMAGE = 'P18';
    case SUBCLASS_OF = 'P279';
    case INSTANCE_OF = 'P31';
    case COUNTRY = 'P17';
    case OFFICIAL_LANGUAGE = 'P37';
    case LOCATION = 'P131';
    case DATE_OF_BIRTH = 'P569';
    case DATE_OF_DEATH = 'P570';
    case PLACE_OF_BIRTH = 'P19';
    case PLACE_OF_DEATH = 'P20';
    case SEX_OR_GENDER = 'P21';
    case CITIZENSHIP = 'P27';
    case OCCUPATION = 'P106';
    case DESCRIPTION = 'P2094';
    case OFFICIAL_NAME = 'P1448';
    case WEBSITE = 'P856';
    case FACEBOOK_ID = 'P2013';
    case TWITTER_ID = 'P2002';
    case INCEPTION = 'P571';
    case DISSOLVED = 'P576';
    case HEADQUARTERS = 'P159';
    case FOUNDED_BY = 'P740';
    case AWARD_RECEIVED = 'P166';

    public function label(): string
    {
        return match($this) {
            self::IMAGE => 'image',
            self::SUBCLASS_OF => 'subclass of',
            self::INSTANCE_OF => 'instance of',
            self::COUNTRY => 'country',
            self::OFFICIAL_LANGUAGE => 'official language',
            self::LOCATION => 'location',
            self::DATE_OF_BIRTH => 'date of birth',
            self::DATE_OF_DEATH => 'date of death',
            self::PLACE_OF_BIRTH => 'place of birth',
            self::PLACE_OF_DEATH => 'place of death',
            self::SEX_OR_GENDER => 'sex or gender',
            self::CITIZENSHIP => 'citizenship',
            self::OCCUPATION => 'occupation',
            self::DESCRIPTION => 'description',
            self::OFFICIAL_NAME => 'official name',
            self::WEBSITE => 'website',
            self::FACEBOOK_ID => 'Facebook ID',
            self::TWITTER_ID => 'Twitter ID',
            self::INCEPTION => 'inception',
            self::DISSOLVED => 'dissolved',
            self::HEADQUARTERS => 'headquarters',
            self::FOUNDED_BY => 'founded by',
            self::AWARD_RECEIVED => 'award received',
        };
    }

    public static function fromCode(string $code): ?self
    {
        foreach (self::cases() as $case) {
            if ($case->value === $code) {
                return $case;
            }
        }
        return null;
    }
}
