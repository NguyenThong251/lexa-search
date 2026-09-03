<?php

namespace Lexa\Analysis;

/**
 * Character-class of a token, decided once by the Token_Classifier.
 * This is the SOLE routing authority — language packs only react to it.
 */
final class TokenClass
{
    public const WORD_DIACRITIC = 'WORD_DIACRITIC'; // có dấu / chữ ngoài ASCII (Việt, …)
    public const WORD_LATIN     = 'WORD_LATIN';     // chữ ASCII thuần (brand, từ tiếng Anh)
    public const ALNUM_CODE     = 'ALNUM_CODE';     // mã: chữ + số (HS7601, AKV3005DK-F)
    public const NUMERIC_UNIT   = 'NUMERIC_UNIT';   // số / số+đơn vị (7601, 220V, 3200mm)
    public const CJK            = 'CJK';            // Hán/Nhật/Hàn (cho sau này)
    public const OTHER          = 'OTHER';
}
