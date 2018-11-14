# TranscriptionTool-Plugin

## Database table format
### Transcription rules

Necessary columns:
- **Type**: enum('Base', 'Diacritic', 'Special', 'Blank')
- **Beta**: Transcription of the character/diactric. The following format is expected:
  - Base characters: \[a-z\]\[1-7\] (Characters a-z are treated as base characters automatically)
  - Diakritics: \[^a-z1-7\]\[1-7\]? (Preferably special characters from the ASCII range)
  - Special characters: No specific rules, except no collision with values from the first two types allowed
  - Blanks: No specific rules, except no collision with values from the first two types allowed
- **Beta_Example**: Only relevant for diacritics. Gives an exemplary combintion of this diactric with a base character
- **Position**: enum('n/a', 'above', 'below', 'direct', 'after') Only relevant for diacritics. Describes the position of the diacritic in relation to the base sign. (direct is used for typographic variations of the base sign)
- **Group**: varchar. The rules are sorted alphabetically by this value, if it is empty by the column **Beta**
- **Description**: varchar
- **Comment**: varchar
- **Depiction**: varchar. Unicode representation of the example (for diacritics) or character (all others). If this field is empty a png image from the images sub-folder with name <Beta>.png is used
  
