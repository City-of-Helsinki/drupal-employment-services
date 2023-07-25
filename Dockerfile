# Use the official Elasticsearch image as the base image
FROM docker.elastic.co/elasticsearch/elasticsearch:7.17.6
# Install the elasticsearch-analysis-raudikko plugin
RUN bin/elasticsearch-plugin install https://github.com/EvidentSolutions/elasticsearch-analysis-raudikko/releases/download/v0.1.1/elasticsearch-analysis-raudikko-0.1.1-es7.17.6.zip
# Expose the Elasticsearch port
EXPOSE 9200
EXPOSE 9300
# Start Elasticsearch
CMD ["bin/elasticsearch"]
