ALTER TABLE flashcard_tags
  DROP COLUMN IF EXISTS is_book,
  DROP COLUMN IF EXISTS is_verb_tense,
  DROP COLUMN IF EXISTS is_sentence_type,
  DROP COLUMN IF EXISTS is_lexical_chunk,
  DROP COLUMN IF EXISTS is_relation_type,
  DROP COLUMN IF EXISTS is_word,
  DROP COLUMN IF EXISTS is_month,
  DROP COLUMN IF EXISTS is_day,
  DROP COLUMN IF EXISTS is_year;
