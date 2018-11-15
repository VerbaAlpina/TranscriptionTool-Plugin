# TranscriptionTool-Plugin

## Database tables format
### Transcription rules

Necessary columns:
- **Kind**: enum('Base', 'Diacritic', 'Special', 'Blank')
- **Beta**: Transcription of the character/diactric. The following format is expected:
  - Base characters: \[a-z\]\[1-7\] (Characters a-z are treated as base characters automatically)
  - Diakritics: \[^a-z1-7\]\[1-7\]? (Preferably special characters from the ASCII range)
  - Special characters: No specific rules, except no collision with values from the first two types allowed
  - Blanks: No specific rules, except no collision with values from the first two types allowed
- **Beta_Example**: Not relevant for base characters. Gives an exemplary combination of this diactric/character and other characters
- **Position**: enum('n/a', 'above', 'below', 'direct', 'after', 'inherited') Only relevant for diacritics. Describes the position of the diacritic in relation to the base character. (direct is used for typographic variations of the base character)
- **Sort_Order**: varchar. The rules are sorted alphabetically by this value, if it is empty by the column **Beta**
- **Description**: varchar
- **Comment**: varchar
- **Depiction**: varchar. Unicode representation of the example (for diacritics) or character (all others). If this field is empty a png image from the images sub-folder with name \<Beta\>.png is used

### Codepage Original

Necessary columns:
  
- **Beta**: varchar
- **Original**: varchar

### Attestations

Necessary columns:

- **Id_Attestation** unsigned primary key auto-increment
- **Id_Stimulus** foreign key to stimulus table
- **Id_Informant** foreign key to informant table
- **Attestation** varchar
- **Transcribed_By** varchar
- **Created** timestamp
- **Classification** enum('A', 'P', 'M')
- **Tokenized** boolean

### Codepage IPA (needed for tokenization)

Contains a mapping from characters in beta code (in general base character + diacritics) to their IPA equivalent. Accents are treated separatly by the conversion routine, so the combinations must not contain any accent diacritics. These are listed separatly (only the accent diactric itself) and marked as "Accent". Regular space characters and all other character that separate two tokens have to be marked as "Blank". All latin letter characters that are vowels have to be marked as "Vowel". All other characters should be marked by the generic type "Character". (Only the first letter is used to define if a character is a vowel or not, so combination of latin letters and numbers or diacritics do not need to be marked as "Vowel".)

Necessary columns:
  
- **Source** varchar
- **Beta**: varchar
- **IPA**: varchar
- **Kind** enum('Character', 'Accent', 'Blank', 'Vowel')
