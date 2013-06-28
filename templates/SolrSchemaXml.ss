<?xml version="1.0" encoding="UTF-8" ?>
<schema name="silverstripe" version="1.5">
<!--

NOTE:  All fields output by the SilverStripe Solr config generator have a type of 'text_ws'.
       You should pick the type that best fits that field from the available fieldTypes
       described below.
       Mostly you should use string for fields that are enumerated (true/false, 0/1, NZD/AUD/USD/GBP)
       and text_en_splitting for things that are made up of english words you want to search inside.

-->
    <types>

        <fieldType name="string" class="solr.StrField" sortMissingLast="true"
            omitNorms="true"/>
        <fieldType name="long" class="solr.TrieLongField" precisionStep="0"
            omitNorms="true" positionIncrementGap="0"/>
        <fieldType name="float" class="solr.TrieFloatField" precisionStep="0"
            omitNorms="true" positionIncrementGap="0"/>
        <fieldType name="date" class="solr.TrieDateField" precisionStep="0"
            omitNorms="true" positionIncrementGap="0"/>
        <fieldType name="currency" class="solr.CurrencyField"
             precisionStep="8" currencyConfig="currency.xml" defaultCurrency="NZD" />
        <fieldType name="text" class="solr.TextField"
            positionIncrementGap="100">
            <analyzer>
                <tokenizer class="solr.WhitespaceTokenizerFactory"/>
                <filter class="solr.StopFilterFactory"
                    ignoreCase="true" words="stopwords.txt"/>
                <filter class="solr.WordDelimiterFilterFactory"
                    generateWordParts="1" generateNumberParts="1"
                    catenateWords="1" catenateNumbers="1" catenateAll="0"
                    splitOnCaseChange="1"/>
                <filter class="solr.LowerCaseFilterFactory"/>
                <filter class="solr.EnglishPorterFilterFactory"
                    protected="protwords.txt"/>
                <filter class="solr.RemoveDuplicatesTokenFilterFactory"/>
            </analyzer>
        </fieldType>

        <fieldType name="url" class="solr.TextField"
            positionIncrementGap="100">
            <analyzer>
                <tokenizer class="solr.StandardTokenizerFactory"/>
                <filter class="solr.LowerCaseFilterFactory"/>
                <filter class="solr.WordDelimiterFilterFactory"
                    generateWordParts="1" generateNumberParts="1"/>
            </analyzer>
        </fieldType>

        <!-- A field type that splits on word boundaries -->
        <fieldType name="text_ws" class="solr.TextField" positionIncrementGap="100">
          <analyzer>
            <tokenizer class="solr.WhitespaceTokenizerFactory"/>
          </analyzer>
        </fieldType>

        <!-- A general text field that has reasonable, generic
             cross-language defaults: it tokenizes with StandardTokenizer,
         removes stop words from case-insensitive "stopwords.txt"
         (empty by default), and down cases.  At query time only, it
         also applies synonyms. -->
        <fieldType name="text_general" class="solr.TextField" positionIncrementGap="100">
          <analyzer type="index">
            <tokenizer class="solr.StandardTokenizerFactory"/>
            <filter class="solr.StopFilterFactory" ignoreCase="true" words="stopwords.txt" enablePositionIncrements="true" />
            <!-- in this example, we will only use synonyms at query time
            <filter class="solr.SynonymFilterFactory" synonyms="index_synonyms.txt" ignoreCase="true" expand="false"/>
            -->
            <filter class="solr.LowerCaseFilterFactory"/>
          </analyzer>
          <analyzer type="query">
            <tokenizer class="solr.StandardTokenizerFactory"/>
            <filter class="solr.StopFilterFactory" ignoreCase="true" words="stopwords.txt" enablePositionIncrements="true" />
            <filter class="solr.SynonymFilterFactory" synonyms="synonyms.txt" ignoreCase="true" expand="true"/>
            <filter class="solr.LowerCaseFilterFactory"/>
          </analyzer>
        </fieldType>

    <!-- A text field with defaults appropriate for English: it
         tokenizes with StandardTokenizer, removes English stop words
         (lang/stopwords_en.txt), down cases, protects words from protwords.txt, and
         finally applies Porter's stemming.  The query time analyzer
         also applies synonyms from synonyms.txt. -->
    <fieldType name="text_en" class="solr.TextField" positionIncrementGap="100">
      <analyzer type="index">
        <tokenizer class="solr.StandardTokenizerFactory"/>
        <!-- in this example, we will only use synonyms at query time
        <filter class="solr.SynonymFilterFactory" synonyms="index_synonyms.txt" ignoreCase="true" expand="false"/>
        -->
        <!-- Case insensitive stop word removal.
          add enablePositionIncrements=true in both the index and query
          analyzers to leave a 'gap' for more accurate phrase queries.
        -->
        <filter class="solr.StopFilterFactory"
                ignoreCase="true"
                words="lang/stopwords_en.txt"
                enablePositionIncrements="true"
                />
        <filter class="solr.LowerCaseFilterFactory"/>
        <filter class="solr.EnglishPossessiveFilterFactory"/>
        <filter class="solr.KeywordMarkerFilterFactory" protected="protwords.txt"/>
        <!-- Optionally you may want to use this less aggressive stemmer instead of PorterStemFilterFactory:
        <filter class="solr.EnglishMinimalStemFilterFactory"/>
        -->
        <filter class="solr.PorterStemFilterFactory"/>
      </analyzer>
      <analyzer type="query">
        <tokenizer class="solr.StandardTokenizerFactory"/>
        <filter class="solr.SynonymFilterFactory" synonyms="synonyms.txt" ignoreCase="true" expand="true"/>
        <filter class="solr.StopFilterFactory"
            ignoreCase="true"
            words="lang/stopwords_en.txt"
            enablePositionIncrements="true"
            />
        <filter class="solr.LowerCaseFilterFactory"/>
        <filter class="solr.EnglishPossessiveFilterFactory"/>
        <filter class="solr.KeywordMarkerFilterFactory" protected="protwords.txt"/>
        <!-- Optionally you may want to use this less aggressive stemmer instead of PorterStemFilterFactory:
        <filter class="solr.EnglishMinimalStemFilterFactory"/>
        -->
        <filter class="solr.PorterStemFilterFactory"/>
      </analyzer>
    </fieldType>

    <!-- A text field with defaults appropriate for English, plus
     aggressive word-splitting and autophrase features enabled.
     This field is just like text_en, except it adds
     WordDelimiterFilter to enable splitting and matching of
     words on case-change, alpha numeric boundaries, and
     non-alphanumeric chars.  This means certain compound word
     cases will work, for example query "wi fi" will match
     document "WiFi" or "wi-fi".  However, other cases will still
     not match, for example if the query is "wifi" and the
     document is "wi fi" or if the query is "wi-fi" and the
     document is "wifi".
        -->
    <fieldType name="text_en_splitting" class="solr.TextField" positionIncrementGap="100" autoGeneratePhraseQueries="true">
      <analyzer type="index">
        <tokenizer class="solr.WhitespaceTokenizerFactory"/>
        <!-- in this example, we will only use synonyms at query time
        <filter class="solr.SynonymFilterFactory" synonyms="index_synonyms.txt" ignoreCase="true" expand="false"/>
        -->
        <!-- Case insensitive stop word removal.
          add enablePositionIncrements=true in both the index and query
          analyzers to leave a 'gap' for more accurate phrase queries.
        -->
        <filter class="solr.StopFilterFactory"
                ignoreCase="true"
                words="lang/stopwords_en.txt"
                enablePositionIncrements="true"
                />
        <filter class="solr.WordDelimiterFilterFactory" generateWordParts="1" generateNumberParts="1" catenateWords="1" catenateNumbers="1" catenateAll="0" splitOnCaseChange="1"/>
        <filter class="solr.LowerCaseFilterFactory"/>
        <filter class="solr.KeywordMarkerFilterFactory" protected="protwords.txt"/>
        <filter class="solr.PorterStemFilterFactory"/>
      </analyzer>
      <analyzer type="query">
        <tokenizer class="solr.WhitespaceTokenizerFactory"/>
        <filter class="solr.SynonymFilterFactory" synonyms="synonyms.txt" ignoreCase="true" expand="false"/>
        <filter class="solr.StopFilterFactory"
                ignoreCase="true"
                words="lang/stopwords_en.txt"
                enablePositionIncrements="true"
                />
        <filter class="solr.WordDelimiterFilterFactory" generateWordParts="1" generateNumberParts="1" catenateWords="0" catenateNumbers="0" catenateAll="0" splitOnCaseChange="1"/>
        <filter class="solr.LowerCaseFilterFactory"/>
        <filter class="solr.KeywordMarkerFilterFactory" protected="protwords.txt"/>
        <filter class="solr.PorterStemFilterFactory"/>
      </analyzer>
    </fieldType>

    <!-- Less flexible matching, but less false matches.  Probably not ideal for product names,
         but may be good for SKUs.  Can insert dashes in the wrong place and still match. -->
    <fieldType name="text_en_splitting_tight" class="solr.TextField" positionIncrementGap="100" autoGeneratePhraseQueries="true">
      <analyzer>
        <tokenizer class="solr.WhitespaceTokenizerFactory"/>
        <filter class="solr.SynonymFilterFactory" synonyms="synonyms.txt" ignoreCase="true" expand="true"/>
        <filter class="solr.StopFilterFactory" ignoreCase="true" words="lang/stopwords_en.txt"/>
        <filter class="solr.WordDelimiterFilterFactory" generateWordParts="0" generateNumberParts="0" catenateWords="1" catenateNumbers="1" catenateAll="0"/>
        <filter class="solr.LowerCaseFilterFactory"/>
        <filter class="solr.KeywordMarkerFilterFactory" protected="protwords.txt"/>
        <filter class="solr.EnglishMinimalStemFilterFactory"/>
        <!-- this filter can remove any duplicate tokens that appear at the same position - sometimes
             possible with WordDelimiterFilter in conjuncton with stemming. -->
        <filter class="solr.RemoveDuplicatesTokenFilterFactory"/>
      </analyzer>
    </fieldType>

    <!-- comma delimited field type just for categories -->
    <fieldType name="text_comma_delimited" class="solr.TextField">
      <analyzer>
        <tokenizer class="solr.PatternTokenizerFactory" group="-1" pattern=","  />
        <filter class="solr.TrimFilterFactory" updateOffsets="true" />
      </analyzer>
    </fieldType>

    <fieldType name="textSpell" class="solr.TextField" positionIncrementGap="100" omitNorms="true">
        <analyzer type="index">
            <tokenizer class="solr.StandardTokenizerFactory" />
            <filter class="solr.SynonymFilterFactory" synonyms="synonyms.txt" ignoreCase="true" expand="true" />
            <filter class="solr.StopFilterFactory" ignoreCase="true" words="stopwords.txt" />
            <filter class="solr.LowerCaseFilterFactory" />
            <filter class="solr.StandardFilterFactory" />
        </analyzer>
        <analyzer type="query">
            <tokenizer class="solr.StandardTokenizerFactory" />
            <filter class="solr.StopFilterFactory" ignoreCase="true" words="stopwords.txt" />
            <filter class="solr.LowerCaseFilterFactory" />
            <filter class="solr.StandardFilterFactory" />
        </analyzer>
    </fieldType>

    </types>

    <fields>
        <field name="id" type="string" stored="true" indexed="true"/>

        <!-- core fields -->
        <field name="segment" type="string" stored="true" indexed="false"/>
        <field name="digest" type="string" stored="true" indexed="false"/>
        <field name="boost" type="float" stored="true" indexed="false"/>
        <!--

        START Fields generated by SilverStripe SolrLuceneBackend

        -->
<% control Fields %>
        <field name="$Name"
          type="$Type"
          multiValued="$Multiple"
          stored="$Stored"
          indexed="$Indexed"
        />
<% end_control %>
        <!--

        END Fields generated by SilverStripe SolrLuceneBackend

        -->
        <!-- Combined field - copyField copies all other fields in here so we can search everywhere at once -->
        <field name="text" type="text_en_splitting" indexed="true" stored="false" multiValued="true" />
    </fields>

    <uniqueKey>id</uniqueKey>
    <defaultSearchField>text</defaultSearchField>
    <solrQueryParser defaultOperator="OR"/>
    <copyField source="*" dest="text"/>

</schema>
