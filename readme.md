# Elastic Extensible Search

An extensible search implementation for Elastic Search. 

## Installation

`composer require nyeholt/silverstripe-extensible-elastic`

NOTE: Until https://github.com/heyday/silverstripe-elastica/pull/10 is merged in, you will need to add the patch file at

https://gist.github.com/nyeholt/e3f4b1745b9af2cc3265df0e548d2b7c



## Configuration

Add the following to your project's config

```
---
Name: elastic_config
---
nglasl\extensible\ExtensibleSearchPage:
  custom_search_engines:
    Symbiote\ElasticSearch\ElasticaSearchEngine: 'Elastic'

PageController:
  extensions:
    - 'nglasl\extensible\ExtensibleSearchExtension'
    - 'Symbiote\ElasticSearch\ElasticaSearchController'

Page:
  extensions:
    - 'Symbiote\ElasticSearch\ElasticaSearchable'

SilverStripe\Core\Injector\Injector:
  ElasticClient:
    class: Elastica\Client
    constructor:
      host_details: 
        host: elastic
        port: 9200
        # transport: AwsAuthV4 - this is needed for AWS search service compatibility; it adds credentials support
  Symbiote\ElasticSearch\ElasticaSearch:
    properties:
      searchService: %$Heyday\Elastica\ElasticaService
  Heyday\Elastica\ElasticaService:
    class: Symbiote\ElasticSearch\ExtensibleElasticService
    constructor:
      client: %$ElasticClient
      index: my-index

```

To add additional types for selection in an extensible search page config; note namespaces are supported.

```
---
Name: search_page_config
---
Symbiote\ElasticSearch\ElasticaSearch:
  additional_search_types:
    My\Namespaced\Class: Friendly Label

```

Run /dev/tasks/Symbiote-ElasticSearch-VersionedReindexTask


Note: Reindex will _ONLY_ reindex items that have the Searchable extension applied. There's also
a DataDiscovery extension that will grab taxonomy terms if available. 

```
---
Name: elastic_data_config
---
SilverStripe\CMS\Model\SiteTree:
  extensions:
    - Symbiote\ElasticSearch\ElasticaSearchable
    # for extra boosting options - Symbiote\ElasticSearch\DataDiscovery
```



## Details

**How do I use the BoostTerms field?**

BoostTerms are used for subsequent querying, either direct through the builder or by the "Boost values" and
"Boost fields with field/value matches" options on the Extensible Search Page. 

The field hint states to use the word "important" in this field to boost the record super high in result sets. This
requires you to set the "Boost fields with field/value matches" to have an entry of

`BoostTerms:important` : `10` 

in the search page to boost records with that set. Additionally, set the "Boost values" for BoostTerms to be higher
than all other fields for any match to contribute highly. 

**Why the separate ElasticaSearchable extension?** 

The base Heyday Elastic module doesn't handle indexing of Versioned content directly; 
ElasticaSearchable provides a few overrides that take into account versioned content. 
