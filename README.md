# Zimbra integration into Nextcloud

Zimbra integration into Nextcloud provides a dashboard widget for unread emails,
one for upcoming events, a search provider for emails and the ability so search for Zimbra contacts in Nextcloud.

Nextcloud will suggest your Zimbra contacts addresses when you search for a contact via the top-right contacts menu
or when you search for someone to share a file to.

## **🛠️ State of maintenance**

While there are many things that could be done to further improve this app, the app is currently maintained with **limited effort**. This means:

- The main functionality works for the majority of the use cases
- We will ensure that the app will continue to work like this for future releases and we will fix bugs that we classify as 'critical'
- We will not invest further development resources ourselves in advancing the app with new features
- We do review and enthusiastically welcome community PR's

We would be more than excited if you would like to collaborate with us. We will merge pull requests for new features and fixes. We also would love to welcome co-maintainers.

If there is a strong business case for any development of this app, we will consider your wishes for our roadmap. Please [contact your account manager](https://nextcloud.com/enterprise/) to talk about the possibilities.

## 🔧 Configuration

### User settings

The account configuration happens in the "Connected accounts" user settings section.
There, you can choose a Zimbra server address and enter your credentials to connect to your account.

A link to the "Connected accounts" user settings section will be displayed in the widget
for users who didn't configure a Zimbra account.

### Admin settings

You can set a default Zimbra server address in the the "Connected accounts" admin settings section.
