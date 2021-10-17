FROM registry.shanemcc.net/mydnshost-public/api-base AS api

FROM registry.shanemcc.net/mydnshost-public/api-docker-base:latest
MAINTAINER Shane Mc Cormack <dataforce@dataforce.org.uk>

EXPOSE ""

COPY --from=api /dnsapi /dnsapi

COPY . /dnsapi/containerlogger

ENTRYPOINT ["/dnsapi/containerlogger/ContainerLogger.php"]
