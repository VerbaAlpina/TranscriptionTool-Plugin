# TranscriptionTool-Plugin

## Database tables format
### Transcription rules (default name: transcription_rules)

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

### Codepage Original (default name: codepage_original)

Necessary columns:
  
- **Beta**: varchar
- **Original**: varchar

### Stimuli (default name: stimuli)

Necessary columns:

- **Id_Stimulus**: unsigned primary key auto-increment
- **Source**: varchar
- **Map_Number** varchar
- **Sub_Number** varchar
- **Stimulus** varchar


### Informants (default name: informants)

Necessary columns:

- **Id_Informant**: unsigned primary key auto-increment
- **Source**: varchar
- **Informant_Number** varchar
- **Place_Name** varchar
- **Position** unsigned. Used for sorting

### attestations (default name: attestations)

Necessary columns:

- **Id_Attestation**: unsigned primary key auto-increment
- **Id_Stimulus**: foreign key to stimulus table
- **Id_Informant** :foreign key to informant table
- **Attestation**: varchar
- **Transcribed_By**: varchar
- **Created**: timestamp
- **Classification**: enum('A', 'P', 'M')
- **Tokenized**: boolean

### Relationship Attestations <-> Concepts (default name: c_attestattion_concept)

- **Id_Attestation**: foreign key to attestation table
- **Id_Concept**: unsigned (should be foreign key to concept table)

### Locks (default name: locks)

- **Table_Name**: varchar
- **Value**: varchar
- **Locked_By**: varchar
- **Time**: timestamp

### Codepage IPA (needed for tokenization) (default name: codepage_ipa)

Contains a mapping from characters in beta code (in general base character + diacritics) to their IPA equivalent. Accents are treated separatly by the conversion routine, so the combinations must not contain any accent diacritics. These are listed separatly (only the accent diactric itself) and marked as "Accent". Regular space characters and all other character that separate two tokens have to be marked as "Blank". All latin letter characters that are vowels have to be marked as "Vowel". All other characters should be marked by the generic type "Character". (Only the first letter is used to define if a character is a vowel or not, so combination of latin letters and numbers or diacritics do not need to be marked as "Vowel".)

Necessary columns:
  
- **Source** varchar
- **Beta**: varchar
- **IPA**: varchar
- **Kind** enum('Character', 'Accent', 'Blank', 'Vowel')
