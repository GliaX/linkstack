# LinkStack + PHP LDAP extension.
# The stock image ships no php83-ldap, so LDAP bind is impossible without this.
ARG LINKSTACK_VERSION=v4.8.8
FROM linkstackorg/linkstack:${LINKSTACK_VERSION}
USER root
RUN apk add --no-cache php83-ldap
RUN php83 -m | grep -qi '^ldap$' && echo "ldap extension present"
USER apache
