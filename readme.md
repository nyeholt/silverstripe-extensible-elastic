# Elastic Extensible Search

An extensible search implementation for Elastic Search. 


## Configuration

Add the following to your project's config

```
---
Name: elastic_config
---
Injector:
  ElasticClient:
    class: Elastica\Client
    constructor:
      host_details: 
        host: elastic
        port: 9200
        # transport: AwsEs - this is needed for AWS search service compatibility; it adds credentials support
  ElasticaSearch:
    properties:
      searchService: %$ElasticaService
  ElasticaService:
    class: ExtensibleElasticService
    constructor:
      client: %$ElasticClient
      index: your_index_name

ExtensibleSearchPage:
  search_engine_extensions:
    ElasticaSearch: Elastic Search
  extensions:
    - ElasticaSearch
ExtensibleSearchPage_Controller:
  extensions:
    - ElasticaSearch_Controller

```

To add additional types for selection in an extensible search page config; note namespace slashes become underscores

```
---
Name: search_page_config
---
ElasticaSearch:
  additional_search_types:
    My_Namespaced_Class: Friendly Label

```



Run /dev/tasks/Symbiote-Elastica-ReindexTask


Note: Reindex will _ONLY_ reindex items that have the Searchable extension applied. There's also
a DataDiscovery extension that will grab taxonomy terms if available. 

```
---
Name: elastic_data_config
---
SiteTree:
  extensions:
    - Symbiote\Elastica\Searchable
    - Symbiote\Elastica\DataDiscovery
```